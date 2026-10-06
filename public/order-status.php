<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{Database,GuestOrderAccessService,OrderService};

$orderNumber=trim((string)($_GET['order']??''));
$token=trim((string)($_GET['token']??''));
$order=null;
if($orderNumber!=='' && $token!==''){
    try{
        $orders=new OrderService(Database::connection());
        $candidate=$orders->findByNumber($orderNumber);
        if($candidate){
            $access=new GuestOrderAccessService(
                (string)env('APP_KEY',''),
                (string)env('APP_URL','http://127.0.0.1:8080'),
                (int)env('ORDER_TRACKING_LINK_DAYS','90')
            );
            if($access->verify($candidate,$token)) $order=$candidate;
        }
    }catch(Throwable){}
}
if(!$order){http_response_code(404);}
$status=$order?(string)$order['status']:'';
$fd=$order['fulfillment_details']??null;
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $order?htmlspecialchars($order['order_number']).' · Order Status':'Order not found' ?> · Fudge Donuts</title><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="/assets/app.css"></head><body>
<header class="nav"><a class="brand" href="/">Fudge Donuts</a><nav><a href="/">Shop</a><a href="/account.php">Account</a></nav></header>
<main class="section narrow guest-order-page">
<?php if(!$order):?>
<p class="eyebrow">Order status</p><h1>Order link not available.</h1><p>This tracking link is invalid or has expired. Use the most recent order email or sign in to your account if you created one.</p><a class="button" href="/">Back to store</a>
<?php else:?>
<p class="eyebrow">Order status</p><h1><?=htmlspecialchars($order['order_number'])?></h1>
<div class="order-status-hero"><span class="status status-<?=htmlspecialchars($status)?>"><?=htmlspecialchars(ucwords(str_replace('_',' ',$status)))?></span><p>Placed <?=htmlspecialchars($order['created_at'])?></p></div>
<?php if($status==='payment_review'):?><div class="notice">Your payment needs a manual review before fulfillment can continue. We’ll contact you if anything is needed.</div><?php endif;?>
<section class="account-section"><h2>Order</h2><div class="review-list"><?php foreach($order['items'] as $item):$cfg=json_decode((string)$item['configuration_json'],true);?><div class="account-order-item"><div><strong><?=htmlspecialchars(($cfg['name']??'Custom '.(int)$item['pack_size'].' Pack'))?> × <?=(int)$item['quantity']?></strong><p><?php foreach($cfg['items']??[] as $f):?><?=htmlspecialchars((string)$f['name'])?> × <?=(int)$f['quantity']?> · <?php endforeach;?></p></div><strong><?=money((int)$item['line_total_cents'])?></strong></div><?php endforeach;?></div><div class="review-total grand"><span>Total</span><strong><?=money((int)$order['total_cents'])?></strong></div></section>
<section class="account-section"><h2>Fulfillment</h2><div class="address-card"><strong><?=htmlspecialchars($order['fulfillment_name'])?></strong><?php if($fd):?><?php if(!empty($fd['tracking_number'])):?><p><?=htmlspecialchars((string)$fd['carrier'])?> · <?=htmlspecialchars((string)$fd['tracking_number'])?><?php if(!empty($fd['tracking_url'])):?><br><a href="<?=htmlspecialchars((string)$fd['tracking_url'])?>" target="_blank" rel="noopener">Track shipment</a><?php endif;?></p><?php endif;?><?php if(!empty($fd['pickup_instructions'])):?><p><strong>Pickup instructions</strong><br><?=nl2br(htmlspecialchars((string)$fd['pickup_instructions']))?></p><?php endif;?><?php if(!empty($fd['pickup_ready_at'])):?><p>Pickup ready: <?=htmlspecialchars((string)$fd['pickup_ready_at'])?></p><?php endif;?><?php endif;?></div></section>
<p class="guest-order-privacy">For privacy, this page does not display your full email address or shipping address.</p>
<?php if(!empty($_SESSION['user_id'])):?><a class="button" href="/account.php">View account</a><?php else:?><a class="button" href="/">Continue shopping</a><?php endif;?>
<?php endif;?>
</main></body></html>