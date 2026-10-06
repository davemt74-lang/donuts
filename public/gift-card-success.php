<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{Database,GiftCardService};
$sessionId=trim((string)($_GET['session_id']??''));$purchase=null;
if($sessionId!==''){
    try{$purchase=(new GiftCardService(Database::connection(),(string)env('APP_KEY','')))->purchaseByStripeSession($sessionId);}catch(Throwable){}
}
$paid=($purchase['status']??'')==='paid';
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Gift Card Status · Fudge Donuts</title><link rel="stylesheet" href="<?=htmlspecialchars(asset_url('/assets/app.css'))?>"></head><body><main class="section narrow"><p class="eyebrow">Gift card</p>
<?php if($paid):?><h1>Gift card confirmed.</h1><p>The gift card has been issued and queued for delivery to <?=htmlspecialchars((string)$purchase['recipient_email'])?>.</p>
<?php else:?><h1>Payment submitted.</h1><p>We’re waiting for Stripe’s signed confirmation. The gift card will only be issued after payment is verified.</p><?php endif;?>
<?php if($purchase):?><div class="address-card"><strong><?=money((int)$purchase['amount_cents'])?></strong><p>Recipient: <?=htmlspecialchars((string)$purchase['recipient_email'])?><br>Status: <?=htmlspecialchars(ucfirst((string)$purchase['status']))?></p></div><?php endif;?>
<div class="actions"><a class="button" href="/">Back to store</a><a class="button secondary" href="/gift-cards.php">Send another gift card</a></div></main></body></html>