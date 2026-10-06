<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{CartService,CatalogRepository,Database,DiscountService,InventoryService,OrderService,PackBuilderService,PaymentRepository,ShippingService,StripeService};

if($_SERVER['REQUEST_METHOD']!=='POST'){header('Location: /checkout-review.php');exit;}
verify_csrf($_POST['_csrf']??null);
if(empty($_SESSION['checkout'])||empty($_SESSION['fulfillment'])){header('Location: /checkout.php');exit;}

$db=Database::connection();$catalog=new CatalogRepository($db);
$cart=(new CartService(new PackBuilderService($catalog),new DiscountService($db)))->summary($_SESSION,$_SESSION['coupon']??null);
$shipping=(new ShippingService($db))->quote((string)$_SESSION['fulfillment']['code'],(string)$_SESSION['checkout']['postal_code'],$cart['total_cents']);
$inventory=new InventoryService($db);$inventory->validateCart($cart);
$orderService=new OrderService($db);
$order=$orderService->create(!empty($_SESSION['user_id'])?(int)$_SESSION['user_id']:null,$cart,$_SESSION['checkout'],$shipping);
$payments=new PaymentRepository($db);
$payment=$payments->createSession(!empty($_SESSION['user_id'])?(int)$_SESSION['user_id']:null,(int)$order['total_cents'],['order_id'=>$order['id'],'order_number'=>$order['order_number']]);

$base=rtrim((string)env('APP_URL','http://127.0.0.1:8080'),'/');
$inventory->reserveOrder((int)$order['id'],$cart);
$stripe=new StripeService((string)env('STRIPE_SECRET_KEY',''),(string)env('STRIPE_WEBHOOK_SECRET',''));
try{
  $session=$stripe->createCheckoutSession([
    'mode'=>'payment',
    'success_url'=>$base.'/payment-success.php?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url'=>$base.'/checkout-review.php',
    'customer_email'=>$order['email'],
    'client_reference_id'=>$order['order_number'],
    'metadata[order_id]'=>(string)$order['id'],
    'automatic_tax[enabled]'=>'true',
    'line_items[0][quantity]'=>'1',
    'line_items[0][price_data][currency]'=>'usd',
    'line_items[0][price_data][unit_amount]'=>(string)$order['total_cents'],
    'line_items[0][price_data][product_data][name]'=>'Fudge Donuts order '.$order['order_number'],
  ],$payment['idempotency_key']);
  $payments->attachProviderSession((int)$payment['id'],(string)$session['id']);
  $orderService->attachStripeSession((int)$order['id'],(string)$session['id']);
  header('Location: '.(string)$session['url'],true,303);exit;
}catch(Throwable $e){
  $inventory->releaseOrder((int)$order['id']);
  $orderService->markPaymentFailedByStripeSession((string)($session['id']??''),'Stripe checkout initialization failed');
  http_response_code(503);echo 'Payment checkout could not be started. Please try again.';
}
