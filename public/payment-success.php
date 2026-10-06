<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{Database,GuestOrderAccessService,OrderService};

$sessionId=trim((string)($_GET['session_id']??''));
$order=null;
if($sessionId!=='') $order=(new OrderService(Database::connection()))->findByStripeSession($sessionId);
$status=(string)($order['status']??'pending_payment');
$paid=$status==='paid';$guestOrderUrl='';
if($paid && $order && empty($_SESSION['user_id'])){
    try{$guestOrderUrl=(new GuestOrderAccessService((string)env('APP_KEY',''),(string)env('APP_URL','http://127.0.0.1:8080'),(int)env('ORDER_TRACKING_LINK_DAYS','90')))->link($order);}catch(Throwable){}
}
if($paid && $order){
    unset(
        $_SESSION['cart'],
        $_SESSION['coupon'],
        $_SESSION['checkout'],
        $_SESSION['fulfillment'],
        $_SESSION['checkout_attempt_token'],
        $_SESSION['active_order_id'],
        $_SESSION['pending_box']
    );
    foreach(array_keys($_SESSION) as $key) if(str_starts_with((string)$key,'builder_')) unset($_SESSION[$key]);
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Payment status · Fudge Donuts</title><link rel="stylesheet" href="<?=htmlspecialchars(asset_url('/assets/app.css'))?>"></head><body><main class="section narrow"><p class="eyebrow">Payment status</p><?php if($paid):?><h1>Order confirmed.</h1><p>Your payment has been confirmed and your order is now in our system.</p><?php else:?><h1>Payment submitted.</h1><p>We’re waiting for Stripe’s signed confirmation. Your order will move forward only after that confirmation arrives.</p><?php endif;?><?php if($order):?><div class="address-card"><strong><?=htmlspecialchars($order['order_number'])?></strong><p>Status: <?=htmlspecialchars(ucwords(str_replace('_',' ',$status)))?></p></div><?php endif;?><div class="actions"><?php if(!empty($_SESSION['user_id'])):?><a class="button" href="/account.php">View account</a><?php elseif($guestOrderUrl!==''):?><a class="button" href="<?=htmlspecialchars($guestOrderUrl)?>">Track this order</a><a class="button secondary" href="/">Continue shopping</a><?php else:?><a class="button" href="/">Back home</a><?php endif;?></div></main></body></html>