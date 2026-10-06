<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{CartService,CatalogRepository,Database,DiscountService,PackBuilderService,ShippingService};
if(empty($_SESSION['checkout'])){header('Location: /checkout.php');exit;}
$db=Database::connection();$catalog=new CatalogRepository($db);
$summary=(new CartService(new PackBuilderService($catalog),new DiscountService($db)))->summary($_SESSION,$_SESSION['coupon']??null);
$c=$_SESSION['checkout'];$shipping=new ShippingService($db);$methods=$shipping->methodsFor($c['postal_code'],$summary['total_cents']);$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf($_POST['_csrf']??null);
 try{
   $method=$shipping->quote((string)($_POST['fulfillment_method']??''),$c['postal_code'],$summary['total_cents']);
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
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Review Order · Fudge Donuts</title><link rel="stylesheet" href="/assets/app.css"></head><body><header class="nav"><a class="brand" href="/">Fudge Donuts</a></header><main class="section narrow"><p class="eyebrow">Final review</p><h1>Almost there.</h1>
<?php if($error):?><div class="notice error"><?=htmlspecialchars($error)?></div><?php endif;?>
<div class="address-card"><strong><?=htmlspecialchars($c['first_name'].' '.$c['last_name'])?></strong><p><?=htmlspecialchars($c['line1'])?><br><?=htmlspecialchars($c['city'].', '.$c['region'].' '.$c['postal_code'])?><br><?=htmlspecialchars($c['email'])?></p></div>
<?php if($c['gift_message']):?><div class="address-card"><strong>Gift message</strong><p><?=nl2br(htmlspecialchars($c['gift_message']))?></p></div><?php endif;?>
<h2>Delivery</h2><form method="post" class="fulfillment-options"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>">
<?php foreach($methods as $m):?><label class="method-card"><input type="radio" name="fulfillment_method" value="<?=htmlspecialchars($m['code'])?>" <?=($selected['code']??'')===$m['code']?'checked':''?> required><span><strong><?=htmlspecialchars($m['name'])?></strong><small><?=$m['type']==='pickup'?'Available for your ZIP code':($m['price_cents']===0?'Free shipping':money($m['price_cents']))?></small></span><b><?=$m['price_cents']===0?'Free':money($m['price_cents'])?></b></label><?php endforeach;?>
<button class="button secondary">Save delivery method</button></form>
<div class="review-total"><span>Cart</span><strong><?=money($summary['total_cents'])?></strong></div>
<div class="review-total"><span>Delivery</span><strong><?=$selected?($selected['price_cents']===0?'Free':money((int)$selected['price_cents'])):'—'?></strong></div>
<div class="review-total grand"><span>Total before tax</span><strong><?=money($grand)?></strong></div>
<div class="actions"><a class="button secondary" href="/checkout.php">Edit details</a><?php if($selected):?><form method="post" action="/pay.php"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><button class="button" type="submit">Pay securely with Stripe</button></form><?php endif;?></div>
</main></body></html>