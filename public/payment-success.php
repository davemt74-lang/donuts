<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{Database,OrderService};

$sessionId=trim((string)($_GET['session_id']??''));
$order=null;
if($sessionId!=='') $order=(new OrderService(Database::connection()))->findByStripeSession($sessionId);
$status=(string)($order['status']??'pending_payment');
$paid=$status==='paid';
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Payment status · Fudge Donuts</title><link rel="stylesheet" href="/assets/app.css"></head><body><main class="section narrow"><p class="eyebrow">Payment status</p><?php if($paid):?><h1>Order confirmed.</h1><p>Your payment has been confirmed and your order is now in our system.</p><?php else:?><h1>Payment submitted.</h1><p>We’re waiting for Stripe’s signed confirmation. Your order will move forward only after that confirmation arrives.</p><?php endif;?><?php if($order):?><div class="address-card"><strong><?=htmlspecialchars($order['order_number'])?></strong><p>Status: <?=htmlspecialchars(ucwords(str_replace('_',' ',$status)))?></p></div><?php endif;?><div class="actions"><?php if(!empty($_SESSION['user_id'])):?><a class="button" href="/account.php">View account</a><?php else:?><a class="button" href="/">Back home</a><?php endif;?></div></main></body></html>