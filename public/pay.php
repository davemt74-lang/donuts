<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{CartService,CatalogRepository,Database,DiscountService,InventoryService,OrderService,PackBuilderService,PaymentRepository,PresetPackService,ShippingService,StripeService,TaxService};

if($_SERVER['REQUEST_METHOD']!=='POST'){header('Location: /checkout-review.php');exit;}
verify_csrf($_POST['_csrf']??null);
if(empty($_SESSION['checkout'])||empty($_SESSION['fulfillment'])){header('Location: /checkout.php');exit;}

$db=Database::connection();$catalog=new CatalogRepository($db);
$cart=(new CartService(new PackBuilderService($catalog),new DiscountService($db),new PresetPackService($catalog)))->summary($_SESSION,$_SESSION['coupon']??null);
if(empty($cart['items'])){header('Location: /cart.php');exit;}

$shipping=(new ShippingService($db))->quote((string)$_SESSION['fulfillment']['code'],(string)$_SESSION['checkout']['postal_code'],$cart['total_cents']);
$inventory=new InventoryService($db);$inventory->validateCart($cart);
$orderService=new OrderService($db);
$_SESSION['checkout_attempt_token'] ??= bin2hex(random_bytes(32));
$order=$orderService->create(
    !empty($_SESSION['user_id'])?(int)$_SESSION['user_id']:null,
    $cart,
    $_SESSION['checkout'],
    $shipping,
    (string)$_SESSION['checkout_attempt_token']
);
$_SESSION['active_order_id']=(int)$order['id'];

$base=rtrim((string)env('APP_URL','http://127.0.0.1:8080'),'/');
$payments=new PaymentRepository($db);$taxService=new TaxService($db);
$stripe=new StripeService((string)env('STRIPE_SECRET_KEY',''),(string)env('STRIPE_WEBHOOK_SECRET',''));

if(in_array((string)$order['status'],['paid','payment_review'],true)){
    $sid=(string)($order['stripe_checkout_session_id']??'');
    if($sid!==''){header('Location: '.$base.'/payment-success.php?session_id='.rawurlencode($sid),true,303);exit;}
    header('Location: /account.php',true,303);exit;
}

if((string)$order['status']==='pending_payment' && !empty($order['stripe_checkout_session_id'])){
    $existingId=(string)$order['stripe_checkout_session_id'];
    try{
        $existing=$stripe->retrieveCheckoutSession($existingId);
        $stripeStatus=(string)($existing['status']??'');
        if($stripeStatus==='open' && !empty($existing['url'])){
            header('Location: '.(string)$existing['url'],true,303);exit;
        }
        if($stripeStatus==='complete'){
            header('Location: '.$base.'/payment-success.php?session_id='.rawurlencode($existingId),true,303);exit;
        }
        if($stripeStatus==='expired'){
            $payments->markFailedByProviderSession($existingId);
            $orderService->markPaymentFailedByStripeSession($existingId,'Stripe Checkout session expired before retry.');
            $inventory->releaseOrder((int)$order['id']);
            $order=$orderService->preparePaymentAttempt((int)$order['id']);
        }else{
            \FudgeDonuts\HttpResponseService::send(
                503,
                'Secure checkout is temporarily unavailable.',
                'We couldn’t safely resume this payment session. Return to checkout and try again in a moment.',
                [
                    ['label'=>'Return to checkout','href'=>'/checkout-review.php'],
                    ['label'=>'Contact support','href'=>'/contact.php']
                ]
            );
        }
    }catch(Throwable $e){
        \FudgeDonuts\ObservabilityService::captureThrowable($e,dirname(__DIR__),'payment_session_verification_failure');
        \FudgeDonuts\HttpResponseService::send(
            503,
            'We couldn’t verify your payment session.',
            'For your protection, we stopped here instead of guessing about the payment state. Return to checkout or contact support if you need help.',
            [
                ['label'=>'Return to checkout','href'=>'/checkout-review.php'],
                ['label'=>'Contact support','href'=>'/contact.php']
            ],
            \FudgeDonuts\ObservabilityService::requestId()
        );
    }
}else{
    $order=$orderService->preparePaymentAttempt((int)$order['id']);
}

$holdMinutes=max(30,min(120,(int)env('CHECKOUT_HOLD_MINUTES','30')));
$expiresAtUnix=time()+($holdMinutes*60);
$expiresAtDb=gmdate('Y-m-d H:i:s',$expiresAtUnix);

$payment=$payments->createSession(!empty($_SESSION['user_id'])?(int)$_SESSION['user_id']:null,(int)$order['total_cents'],[
    'order_id'=>$order['id'],
    'order_number'=>$order['order_number'],
    'checkout_attempt'=>hash('sha256',(string)$_SESSION['checkout_attempt_token']),
]);

$inventory->reserveOrder((int)$order['id'],$cart,$expiresAtDb);
$taxService->snapshotOrder((int)$order['id']);
try{
  $stripeParams=[
    'mode'=>'payment',
    'success_url'=>$base.'/payment-success.php?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url'=>$base.'/checkout-review.php',
    'customer_email'=>$order['email'],
    'client_reference_id'=>$order['order_number'],
    'metadata[order_id]'=>(string)$order['id'],
    'expires_at'=>(string)$expiresAtUnix,
    'line_items[0][quantity]'=>'1',
    'line_items[0][price_data][currency]'=>'usd',
    'line_items[0][price_data][unit_amount]'=>(string)$order['total_cents'],
    'line_items[0][price_data][product_data][name]'=>'Fudge Donuts order '.$order['order_number'],
  ];
  $stripeParams=array_merge($stripeParams,$taxService->checkoutParams());
  $session=$stripe->createCheckoutSession($stripeParams,$payment['idempotency_key']);
  $payments->attachProviderSession((int)$payment['id'],(string)$session['id']);
  $orderService->attachStripeSession((int)$order['id'],(string)$session['id']);
  header('Location: '.(string)$session['url'],true,303);exit;
}catch(Throwable $e){
  $inventory->releaseOrder((int)$order['id']);
  $payments->markFailed((int)$payment['id'],'Stripe checkout initialization failed');
  $orderService->markPaymentInitializationFailed((int)$order['id'],'Stripe checkout initialization failed');
  \FudgeDonuts\ObservabilityService::captureThrowable($e,dirname(__DIR__),'payment_checkout_initialization_failure');
  \FudgeDonuts\HttpResponseService::send(
      503,
      'Secure checkout couldn’t start.',
      'Your order is still safe. Return to checkout and try again, or contact support if the problem continues.',
      [
          ['label'=>'Return to checkout','href'=>'/checkout-review.php'],
          ['label'=>'Contact support','href'=>'/contact.php']
      ],
      \FudgeDonuts\ObservabilityService::requestId()
  );
}
