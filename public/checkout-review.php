<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{CartService,CatalogRepository,Database,DiscountService,PackBuilderService,PresetPackService,ShippingService,TaxService};
if(empty($_SESSION['checkout'])){header('Location: /checkout.php');exit;}
$db=Database::connection();$catalog=new CatalogRepository($db);
$summary=(new CartService(new PackBuilderService($catalog),new DiscountService($db),new PresetPackService($catalog)))->summary($_SESSION,$_SESSION['coupon']??null);
$c=$_SESSION['checkout'];$shipping=new ShippingService($db);$methods=$shipping->methodsFor($c['postal_code'],$summary['total_cents']);$fulfillmentSettings=$shipping->settings();$taxSettings=(new TaxService($db))->settings();$error='';
$_SESSION['checkout_attempt_token'] ??= bin2hex(random_bytes(32));
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf($_POST['_csrf']??null);
 try{
   $method=$shipping->quote((string)($_POST['fulfillment_method']??''),$c['postal_code'],$summary['total_cents']);
   if(($method['code']??'')!==(string)(($_SESSION['fulfillment']['code']??''))){
       unset($_SESSION['checkout_attempt_token'],$_SESSION['active_order_id']);
       $_SESSION['checkout_attempt_token']=bin2hex(random_bytes(32));
   }
   $_SESSION['fulfillment']=$method;
   header('Location: /checkout-review.php?ready=1');exit;
 }catch(Throwable $e){$error=$e->getMessage();}
}
$selected=$_SESSION['fulfillment']??null;
if($selected){
 try{$selected=$shipping->quote((string)$selected['code'],$c['postal_code'],$summary['total_cents']);$_SESSION['fulfillment']=$selected;}
 catch(Throwable){unset($_SESSION['fulfillment']);$selected=null;}
}
$grand=$summary['total_cents']+(int)($selected['price_cents']??0);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Review Order · Fudge Donuts</title><link rel="stylesheet" href="<?=htmlspecialchars(asset_url('/assets/app.css'))?>"></head><body><a class="skip-link" href="#main-content">Skip to main content</a><header class="nav"><a class="brand" href="/">Fudge Donuts</a></header><main id="main-content" tabindex="-1" class="section narrow"><p class="eyebrow">Final review</p><h1>Almost there.</h1>
<?php if($error):?><div class="notice error" role="alert"><?=htmlspecialchars($error)?></div><?php endif;?>
<div class="address-card"><strong><?=htmlspecialchars($c['first_name'].' '.$c['last_name'])?></strong><p><?=htmlspecialchars($c['line1'])?><br><?=htmlspecialchars($c['city'].', '.$c['region'].' '.$c['postal_code'])?><br><?=htmlspecialchars($c['email'])?></p></div>
<?php if($c['is_gift']):?><div class="address-card"><strong>Gift order</strong><p><?=htmlspecialchars(($c['gift_packaging']??'standard')==='gift-box'?'Gift box':'Standard box')?><?=!empty($c['hide_price'])?' · Prices hidden':''?><?=!empty($c['gift_delivery_date'])?' · Requested '.$c['gift_delivery_date']:''?></p><?php if($c['gift_message']):?><p><?=nl2br(htmlspecialchars($c['gift_message']))?></p><?php endif;?></div><?php endif;?>
<h2 id="delivery-heading">Delivery</h2><?php if(!empty($fulfillmentSettings['shipping_notice'])):?><p class="muted"><?=htmlspecialchars($fulfillmentSettings['shipping_notice'])?></p><?php endif;?><form method="post" class="fulfillment-options" aria-labelledby="delivery-heading"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>">
<?php foreach($methods as $m):?><label class="method-card method-card-detailed"><input type="radio" name="fulfillment_method" value="<?=htmlspecialchars($m['code'])?>" <?=($selected['code']??'')===$m['code']?'checked':''?> required><span><strong><?=htmlspecialchars($m['name'])?></strong><?php if($m['description']):?><small><?=htmlspecialchars($m['description'])?></small><?php endif;?><?php if($m['eta_label']):?><small>Estimated: <?=htmlspecialchars($m['eta_label'])?></small><?php endif;?><?php if($m['type']==='pickup'):?><small>Available for your ZIP code<?php if(!empty($m['settings']['pickup_location_name'])):?> · <?=htmlspecialchars($m['settings']['pickup_location_name'])?><?php endif;?></small><?php elseif($m['price_cents']===0):?><small>Free shipping</small><?php endif;?></span><b><?=$m['price_cents']===0?'Free':money($m['price_cents'])?></b></label><?php if($m['checkout_message']):?><div class="method-note"><?=nl2br(htmlspecialchars($m['checkout_message']))?></div><?php endif;?><?php if($m['type']==='pickup' && !empty($m['settings'])):?><div class="pickup-checkout-details"><?php if(!empty($m['settings']['pickup_address'])):?><strong><?=htmlspecialchars($m['settings']['pickup_address'])?></strong><?php endif;?><?php if(!empty($m['settings']['pickup_hours'])):?><span><?=nl2br(htmlspecialchars($m['settings']['pickup_hours']))?></span><?php endif;?><?php if(!empty($m['settings']['pickup_instructions'])):?><span><?=nl2br(htmlspecialchars($m['settings']['pickup_instructions']))?></span><?php endif;?></div><?php endif;?><?php endforeach;?>
<?php if(!$methods):?><div class="notice error" role="alert">No delivery methods are currently available for this address. Please contact us before ordering.</div><?php else:?><button class="button secondary">Save delivery method</button><?php endif;?></form>
<div class="review-total"><span>Cart</span><strong><?=money($summary['total_cents'])?></strong></div>
<div class="review-total"><span>Delivery</span><strong><?=$selected?($selected['price_cents']===0?'Free':money((int)$selected['price_cents'])):'—'?></strong></div>
<div class="review-total grand"><span>Total before tax</span><strong><?=money($grand)?></strong></div><?php if($taxSettings['automatic_tax_enabled']==='1' && $taxSettings['checkout_notice']!==''):?><div class="method-note"><?=htmlspecialchars($taxSettings['checkout_notice'])?></div><?php endif;?>
<div class="actions"><a class="button secondary" href="/checkout.php">Edit details</a><?php if($selected):?><form method="post" action="/pay.php"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><button class="button" type="submit">Pay securely with Stripe</button></form><?php endif;?></div>
</main></body></html>