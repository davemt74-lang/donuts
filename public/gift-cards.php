<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{Database,GiftCardService,SecurityService,StripeService};

$db=Database::connection();$svc=new GiftCardService($db,(string)env('APP_KEY',''));$error='';
$amounts=$svc->allowedAmounts();
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf($_POST['_csrf']??null);
    try{
        $client=SecurityService::clientIdentifier();$security=new SecurityService($db);
        $security->assertLoginAllowed('gift-card-purchase-ip',$client,10,3600);
        $purchase=$svc->createPurchase(
            (int)($_POST['amount_cents']??0),
            (string)($_POST['purchaser_email']??''),
            (string)($_POST['recipient_email']??''),
            (string)($_POST['recipient_name']??''),
            (string)($_POST['message']??'')
        );
        $security->recordLoginFailure('gift-card-purchase-ip',$client,10,3600);
        $base=rtrim((string)env('APP_URL','http://127.0.0.1:8080'),'/');
        $stripe=new StripeService((string)env('STRIPE_SECRET_KEY',''),(string)env('STRIPE_WEBHOOK_SECRET',''));
        try{
            $session=$stripe->createCheckoutSession([
                'mode'=>'payment',
                'success_url'=>$base.'/gift-card-success.php?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url'=>$base.'/gift-cards.php?cancelled=1',
                'customer_email'=>$purchase['purchaser_email'],
                'client_reference_id'=>'GIFT-'.$purchase['id'],
                'metadata[purchase_type]'=>'gift_card',
                'metadata[gift_card_purchase_id]'=>(string)$purchase['id'],
                'line_items[0][quantity]'=>'1',
                'line_items[0][price_data][currency]'=>'usd',
                'line_items[0][price_data][unit_amount]'=>(string)$purchase['amount_cents'],
                'line_items[0][price_data][product_data][name]'=>'Fudge Donuts Gift Card',
                'line_items[0][price_data][product_data][description]'=>'Digital gift card delivered by email',
            ],'gift_card_purchase_'.$purchase['id']);
            $svc->attachPurchaseStripeSession((int)$purchase['id'],(string)$session['id']);
            header('Location: '.(string)$session['url'],true,303);exit;
        }catch(Throwable $e){
            $svc->failPurchase((int)$purchase['id']);
            throw $e;
        }
    }catch(Throwable $e){
        FudgeDonutsObservabilityService::captureThrowable($e,dirname(__DIR__),'gift_card_checkout_failure');
        $error=$e instanceof InvalidArgumentException?$e->getMessage():'Gift card checkout could not be started. Please try again.';
    }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Gift Cards · Fudge Donuts</title><meta name="description" content="Send a digital Fudge Donuts gift card for a future box of handcrafted fudge donuts."><link rel="stylesheet" href="<?=htmlspecialchars(asset_url('/assets/app.css'))?>"></head><body>
<a class="skip-link" href="#main-content">Skip to main content</a>
<header class="nav"><a class="brand" href="/">Fudge Donuts</a><nav><a href="/#shop">Shop</a><a href="/faq.php">FAQ</a><a href="/cart.php">Cart</a></nav></header>
<main id="main-content" tabindex="-1" class="section narrow"><p class="eyebrow">Send something sweet</p><h1>Fudge Donuts Gift Cards</h1><p>Digital gift cards are delivered by email after secure payment confirmation and can be applied to a future order.</p>
<?php if(isset($_GET['cancelled'])):?><div class="notice" role="status">Gift card checkout was cancelled. Nothing was charged.</div><?php endif;?>
<?php if($error):?><div class="notice error" role="alert"><?=htmlspecialchars($error)?></div><?php endif;?>
<form method="post" class="admin-form gift-card-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>">
<label>Gift card amount<select name="amount_cents" required><?php foreach($amounts as $amount):?><option value="<?=$amount?>"><?=money($amount)?></option><?php endforeach;?></select></label>
<label>Your email<input type="email" name="purchaser_email" required autocomplete="email" maxlength="190" value="<?=htmlspecialchars((string)($_POST['purchaser_email']??''))?>"></label>
<label>Recipient name<input name="recipient_name" autocomplete="name" maxlength="190" value="<?=htmlspecialchars((string)($_POST['recipient_name']??''))?>"></label>
<label>Recipient email<input type="email" name="recipient_email" required autocomplete="email" maxlength="190" value="<?=htmlspecialchars((string)($_POST['recipient_email']??''))?>"></label>
<label>Gift message<textarea name="message" maxlength="500" rows="5" placeholder="Optional message"><?=htmlspecialchars((string)($_POST['message']??''))?></textarea></label>
<button class="button">Continue to secure payment</button></form>
<p class="muted">Gift cards are not cash and are redeemed against eligible Fudge Donuts orders. Any unused balance remains on the card.</p>
</main></body></html>