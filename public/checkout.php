<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{AuthService,CartService,CatalogRepository,CheckoutService,Database,DiscountService,PackBuilderService};

$db=Database::connection();
$catalog=new CatalogRepository($db);
$cart=new CartService(new PackBuilderService($catalog),new DiscountService($db));
$summary=$cart->summary($_SESSION,$_SESSION['coupon']??null);
if(!$summary['items']){header('Location: /cart.php');exit;}
$auth=new AuthService($db);
$user=!empty($_SESSION['user_id'])?$auth->user((int)$_SESSION['user_id']):null;
$addresses=$user?$auth->addresses((int)$user['id']):[];
$default=$addresses[0]??[];
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf($_POST['_csrf']??null);
 try{
  $_SESSION['checkout']=(new CheckoutService())->validate($_POST);
  header('Location: /checkout-review.php');exit;
 }catch(Throwable $e){$error=$e->getMessage();}
}
$value=fn(string $k)=>htmlspecialchars((string)($_POST[$k]??$default[$k]??($k==='email'?($user['email']??''):'')));
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Checkout · Fudge Donuts</title><link rel="stylesheet" href="/assets/app.css"></head><body>
<header class="nav"><a class="brand" href="/">Fudge Donuts</a><nav><a href="/cart.php">Back to cart</a></nav></header>
<main class="section checkout-page"><section><p class="eyebrow">Checkout</p><h1>Where should we send them?</h1><?php if($error):?><div class="notice error"><?=htmlspecialchars($error)?></div><?php endif;?>
<form method="post" class="checkout-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>">
<label>Email<input type="email" name="email" required value="<?=$value('email')?>"></label><div class="two"><label>First name<input name="first_name" required value="<?=$value('first_name')?>"></label><label>Last name<input name="last_name" required value="<?=$value('last_name')?>"></label></div>
<label>Address<input name="line1" required value="<?=$value('line1')?>"></label><label>Apt / suite<input name="line2" value="<?=$value('line2')?>"></label>
<div class="three"><label>City<input name="city" required value="<?=$value('city')?>"></label><label>State<input name="region" required value="<?=$value('region')?>"></label><label>ZIP<input name="postal_code" required value="<?=$value('postal_code')?>"></label></div>
<input type="hidden" name="country" value="US"><label>Phone<input name="phone" value="<?=$value('phone')?>"></label>
<label class="check"><input type="checkbox" name="is_gift" value="1" <?=!empty($_POST['is_gift'])?'checked':''?>> This is a gift</label><label>Gift message<textarea name="gift_message" maxlength="300" placeholder="Optional message"><?=htmlspecialchars((string)($_POST['gift_message']??''))?></textarea></label>
<button class="button">Review order</button></form></section>
<aside class="summary"><h2>Order</h2><div><span><?=$summary['units']?> box<?=($summary['units']===1?'':'es')?></span><strong><?=money($summary['subtotal_cents'])?></strong></div><?php foreach($summary['discounts'] as $d):?><div class="discount"><span><?=htmlspecialchars($d['name'])?></span><strong>−<?=money($d['amount_cents'])?></strong></div><?php endforeach;?><div class="total"><span>Current total</span><strong><?=money($summary['total_cents'])?></strong></div><small>Shipping/tax calculated before payment.</small></aside>
</main></body></html>
