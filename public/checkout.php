<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{AuthService,CartService,CatalogRepository,CheckoutService,Database,DiscountService,OrderService,PackBuilderService,PresetPackService};

$db=Database::connection();
$catalog=new CatalogRepository($db);
$cart=new CartService(new PackBuilderService($catalog),new DiscountService($db),new PresetPackService($catalog));
$summary=$cart->summary($_SESSION,$_SESSION['coupon']??null);
if(!$summary['items']){header('Location: /cart.php');exit;}
$auth=new AuthService($db);
$user=!empty($_SESSION['user_id'])?$auth->user((int)$_SESSION['user_id']):null;
$addresses=$user?$auth->addresses((int)$user['id']):[];
$default=$addresses[0]??[];
$error='';
if(!empty($_SESSION['active_order_id'])){
    try{
        $active=(new OrderService($db))->find((int)$_SESSION['active_order_id']);
        if(!in_array($active['status'],['pending_payment','payment_failed','payment_review'],true)){
            unset($_SESSION['checkout_attempt_token'],$_SESSION['active_order_id'],$_SESSION['fulfillment']);
        }
    }catch(Throwable){
        unset($_SESSION['checkout_attempt_token'],$_SESSION['active_order_id'],$_SESSION['fulfillment']);
    }
}
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf($_POST['_csrf']??null);
 try{
  $validated=(new CheckoutService())->validate($_POST);
  if(($validated!==($_SESSION['checkout']??null))){
      unset($_SESSION['checkout_attempt_token'],$_SESSION['active_order_id'],$_SESSION['fulfillment']);
  }
  $_SESSION['checkout']=$validated;
  header('Location: /checkout-review.php');exit;
 }catch(Throwable $e){$error=$e->getMessage();}
}
$value=fn(string $k)=>htmlspecialchars((string)($_POST[$k]??$default[$k]??($k==='email'?($user['email']??''):'')));
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Checkout · Fudge Donuts</title><link rel="stylesheet" href="<?=htmlspecialchars(asset_url('/assets/app.css'))?>"></head><body>
<a class="skip-link" href="#main-content">Skip to main content</a>
<header class="nav"><a class="brand" href="/">Fudge Donuts</a><nav><a href="/cart.php">Back to cart</a></nav></header>
<main id="main-content" tabindex="-1" class="section checkout-page"><section><p class="eyebrow">Checkout</p><h1>Where should we send them?</h1><?php if($error):?><div class="notice error" role="alert"><?=htmlspecialchars($error)?></div><?php endif;?>
<form method="post" class="checkout-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>">
<label>Email<input type="email" name="email" required autocomplete="email" value="<?=$value('email')?>"></label><div class="two"><label>First name<input name="first_name" required autocomplete="given-name" value="<?=$value('first_name')?>"></label><label>Last name<input name="last_name" required autocomplete="family-name" value="<?=$value('last_name')?>"></label></div>
<label>Address<input name="line1" required autocomplete="address-line1" value="<?=$value('line1')?>"></label><label>Apt / suite<input name="line2" autocomplete="address-line2" value="<?=$value('line2')?>"></label>
<div class="three"><label>City<input name="city" required autocomplete="address-level2" value="<?=$value('city')?>"></label><label>State<input name="region" required autocomplete="address-level1" value="<?=$value('region')?>"></label><label>ZIP<input name="postal_code" required autocomplete="postal-code" inputmode="numeric" value="<?=$value('postal_code')?>"></label></div>
<input type="hidden" name="country" value="US"><label>Phone<input type="tel" name="phone" autocomplete="tel" value="<?=$value('phone')?>"></label>
<label class="check"><input type="checkbox" name="is_gift" value="1" <?=!empty($_POST['is_gift'])?'checked':''?>> This is a gift</label>
<div class="gift-options"><label>Gift packaging<select name="gift_packaging"><option value="standard">Standard box</option><option value="gift-box">Gift box</option></select></label><label class="check"><input type="checkbox" name="hide_price" value="1" <?=!empty($_POST['hide_price'])?'checked':''?>> Hide prices in the package</label><label>Requested delivery date<input type="date" name="gift_delivery_date" min="<?=gmdate('Y-m-d')?>" value="<?=htmlspecialchars((string)($_POST['gift_delivery_date']??''))?>"></label><label>Recipient email<input type="email" name="gift_recipient_email" placeholder="Optional" value="<?=htmlspecialchars((string)($_POST['gift_recipient_email']??''))?>"></label><label>Gift message<textarea name="gift_message" maxlength="300" placeholder="Optional message"><?=htmlspecialchars((string)($_POST['gift_message']??''))?></textarea></label></div>
<div class="allergen-callout compact"><strong>Food allergy notice</strong><span>By continuing, you confirm you have reviewed the ingredient and allergen information for the flavors in your order.</span></div><label class="check policy-consent"><input type="checkbox" name="terms_accepted" value="1" <?=!empty($_POST['terms_accepted'])?'checked':''?> required> I agree to the <a href="/policy.php?type=terms" target="_blank" rel="noopener">Terms of Service</a> and <a href="/policy.php?type=refunds" target="_blank" rel="noopener">Refund & Cancellation Policy</a>, and I have reviewed the <a href="/policy.php?type=privacy" target="_blank" rel="noopener">Privacy Policy</a>.</label><button class="button">Review order</button></form></section>
<aside class="summary"><h2>Order</h2><div><span><?=$summary['units']?> box<?=($summary['units']===1?'':'es')?></span><strong><?=money($summary['subtotal_cents'])?></strong></div><?php foreach($summary['discounts'] as $d):?><div class="discount"><span><?=htmlspecialchars($d['name'])?></span><strong>−<?=money($d['amount_cents'])?></strong></div><?php endforeach;?><div class="total"><span>Current total</span><strong><?=money($summary['total_cents'])?></strong></div><small>Shipping/tax calculated before payment.</small></aside>
</main></body></html>
