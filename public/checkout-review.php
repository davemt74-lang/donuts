<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{CartService,CatalogRepository,Database,DiscountService,GiftCardService,InventoryService,LoyaltyService,OrderService,PackBuilderService,PaymentRepository,PresetPackService,ShippingService,StripeService,TaxService};
if(empty($_SESSION['checkout'])){header('Location: /checkout.php');exit;}
$db=Database::connection();$catalog=new CatalogRepository($db);
$summary=(new CartService(new PackBuilderService($catalog),new DiscountService($db),new PresetPackService($catalog)))->summary($_SESSION,$_SESSION['coupon']??null);
$c=$_SESSION['checkout'];$shipping=new ShippingService($db);$methods=$shipping->methodsFor($c['postal_code'],$summary['total_cents']);$fulfillmentSettings=$shipping->settings();$taxSettings=(new TaxService($db))->settings();$error='';
$giftCards=new GiftCardService($db,(string)env('APP_KEY',''));$giftCard=null;$loyalty=new LoyaltyService($db);$loyaltyAccount=null;$loyaltyQuote=['points'=>0,'discount_cents'=>0,'available_points'=>0];
if(!empty($_SESSION['user_id'])){
    try{
        $loyaltyAccount=$loyalty->account((int)$_SESSION['user_id']);
        $requested=(int)($_SESSION['loyalty_points']??0);
        if($requested>0)$loyaltyQuote=$loyalty->quoteRedemption((int)$_SESSION['user_id'],$requested,$summary['total_cents']);
        else $loyaltyQuote['available_points']=(int)$loyaltyAccount['available_points'];
    }catch(Throwable){unset($_SESSION['loyalty_points']);}
}
if(!empty($_SESSION['gift_card_id'])){
    try{$giftCard=$giftCards->card((int)$_SESSION['gift_card_id']);if($giftCard['status']!=='active'||(int)$giftCard['available_cents']<=0){unset($_SESSION['gift_card_id']);$giftCard=null;}}catch(Throwable){unset($_SESSION['gift_card_id']);}
}
$_SESSION['checkout_attempt_token'] ??= bin2hex(random_bytes(32));
$resetPaymentAttempt=function() use($db,$giftCards): void {
    if(!empty($_SESSION['active_order_id'])){
        $orderService=new OrderService($db);$inventory=new InventoryService($db);$payments=new PaymentRepository($db);
        try{
            $order=$orderService->find((int)$_SESSION['active_order_id']);
            $sid=(string)($order['stripe_checkout_session_id']??'');
            if($sid!=='' && $order['status']==='pending_payment'){
                $stripe=new StripeService((string)env('STRIPE_SECRET_KEY',''),(string)env('STRIPE_WEBHOOK_SECRET',''));
                $session=$stripe->retrieveCheckoutSession($sid);$stripeStatus=(string)($session['status']??'');
                if($stripeStatus==='complete') throw new RuntimeException('Payment already completed. Refresh the payment status before changing checkout options.');
                if($stripeStatus==='open')$stripe->expireCheckoutSession($sid);
                elseif($stripeStatus!=='expired') throw new RuntimeException('Existing payment session could not be safely reset.');
                $payments->markFailedByProviderSession($sid);
                $orderService->markPaymentFailedByStripeSession($sid,'Checkout tender or fulfillment changed.');
            }
            $inventory->releaseOrder((int)$order['id']);$giftCards->releaseForOrder((int)$order['id']);(new LoyaltyService($db))->releaseForOrder((int)$order['id']);
        }catch(Throwable $e){
            throw new RuntimeException('We could not safely reset the existing payment session. Please try again shortly.',0,$e);
        }
    }
    unset($_SESSION['active_order_id'],$_SESSION['checkout_attempt_token']);
    $_SESSION['checkout_attempt_token']=bin2hex(random_bytes(32));
};
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf($_POST['_csrf']??null);
 try{
   $action=(string)($_POST['action']??'fulfillment');
   if($action==='loyalty_apply'){
       if(empty($_SESSION['user_id'])) throw new InvalidArgumentException('Sign in to redeem rewards.');
       $requested=max(0,(int)($_POST['loyalty_points']??0));
       $quote=$loyalty->quoteRedemption((int)$_SESSION['user_id'],$requested,$summary['total_cents']);
       if((int)($_SESSION['loyalty_points']??0)!==(int)$quote['points'])$resetPaymentAttempt();
       $_SESSION['loyalty_points']=(int)$quote['points'];
       header('Location: /checkout-review.php?rewards=applied');exit;
   }
   if($action==='loyalty_remove'){
       if(!empty($_SESSION['loyalty_points']))$resetPaymentAttempt();
       unset($_SESSION['loyalty_points']);
       header('Location: /checkout-review.php?rewards=removed');exit;
   }
   if($action==='gift_card_apply'){
       $card=$giftCards->findByCode((string)($_POST['gift_card_code']??''));
       if(!$card || $card['status']!=='active' || (int)$card['available_cents']<=0) throw new InvalidArgumentException('Gift card is invalid or has no available balance.');
       if((int)($_SESSION['gift_card_id']??0)!==(int)$card['id'])$resetPaymentAttempt();
       $_SESSION['gift_card_id']=(int)$card['id'];
       header('Location: /checkout-review.php?gift=applied');exit;
   }
   if($action==='gift_card_remove'){
       if(!empty($_SESSION['gift_card_id']))$resetPaymentAttempt();
       unset($_SESSION['gift_card_id']);
       header('Location: /checkout-review.php?gift=removed');exit;
   }
   $method=$shipping->quote((string)($_POST['fulfillment_method']??''),$c['postal_code'],$summary['total_cents']);
   if(($method['code']??'')!==(string)(($_SESSION['fulfillment']['code']??'')))$resetPaymentAttempt();
   $_SESSION['fulfillment']=$method;
   header('Location: /checkout-review.php?ready=1');exit;
 }catch(Throwable $e){$error=$e->getMessage();}
}
$selected=$_SESSION['fulfillment']??null;
if($selected){
 try{$selected=$shipping->quote((string)$selected['code'],$c['postal_code'],$summary['total_cents']);$_SESSION['fulfillment']=$selected;}
 catch(Throwable){unset($_SESSION['fulfillment']);$selected=null;}
}
$loyaltyDiscount=(int)($loyaltyQuote['discount_cents']??0);
$grand=max(0,$summary['total_cents']-$loyaltyDiscount)+(int)($selected['price_cents']??0);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Review Order · Fudge Donuts</title><link rel="stylesheet" href="<?=htmlspecialchars(asset_url('/assets/app.css'))?>"><?=analytics_script()?></head><body><a class="skip-link" href="#main-content">Skip to main content</a><header class="nav"><a class="brand" href="/">Fudge Donuts</a></header><main id="main-content" tabindex="-1" class="section narrow"><p class="eyebrow">Final review</p><h1>Almost there.</h1>
<?php if($error):?><div class="notice error" role="alert"><?=htmlspecialchars($error)?></div><?php endif;?>
<div class="address-card"><strong><?=htmlspecialchars($c['first_name'].' '.$c['last_name'])?></strong><p><?=htmlspecialchars($c['line1'])?><br><?=htmlspecialchars($c['city'].', '.$c['region'].' '.$c['postal_code'])?><br><?=htmlspecialchars($c['email'])?></p></div>
<?php if($c['is_gift']):?><div class="address-card"><strong>Gift order</strong><p><?=htmlspecialchars(($c['gift_packaging']??'standard')==='gift-box'?'Gift box':'Standard box')?><?=!empty($c['hide_price'])?' · Prices hidden':''?><?=!empty($c['gift_delivery_date'])?' · Requested '.$c['gift_delivery_date']:''?></p><?php if($c['gift_message']):?><p><?=nl2br(htmlspecialchars($c['gift_message']))?></p><?php endif;?></div><?php endif;?>
<h2 id="delivery-heading">Delivery</h2><?php if(!empty($fulfillmentSettings['shipping_notice'])):?><p class="muted"><?=htmlspecialchars($fulfillmentSettings['shipping_notice'])?></p><?php endif;?><form method="post" class="fulfillment-options" aria-labelledby="delivery-heading"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="fulfillment">
<?php foreach($methods as $m):?><label class="method-card method-card-detailed"><input type="radio" name="fulfillment_method" value="<?=htmlspecialchars($m['code'])?>" <?=($selected['code']??'')===$m['code']?'checked':''?> required><span><strong><?=htmlspecialchars($m['name'])?></strong><?php if($m['description']):?><small><?=htmlspecialchars($m['description'])?></small><?php endif;?><?php if($m['eta_label']):?><small>Estimated: <?=htmlspecialchars($m['eta_label'])?></small><?php endif;?><?php if($m['type']==='pickup'):?><small>Available for your ZIP code<?php if(!empty($m['settings']['pickup_location_name'])):?> · <?=htmlspecialchars($m['settings']['pickup_location_name'])?><?php endif;?></small><?php elseif($m['price_cents']===0):?><small>Free shipping</small><?php endif;?></span><b><?=$m['price_cents']===0?'Free':money($m['price_cents'])?></b></label><?php if($m['checkout_message']):?><div class="method-note"><?=nl2br(htmlspecialchars($m['checkout_message']))?></div><?php endif;?><?php if($m['type']==='pickup' && !empty($m['settings'])):?><div class="pickup-checkout-details"><?php if(!empty($m['settings']['pickup_address'])):?><strong><?=htmlspecialchars($m['settings']['pickup_address'])?></strong><?php endif;?><?php if(!empty($m['settings']['pickup_hours'])):?><span><?=nl2br(htmlspecialchars($m['settings']['pickup_hours']))?></span><?php endif;?><?php if(!empty($m['settings']['pickup_instructions'])):?><span><?=nl2br(htmlspecialchars($m['settings']['pickup_instructions']))?></span><?php endif;?></div><?php endif;?><?php endforeach;?>
<?php if(!$methods):?><div class="notice error" role="alert">No delivery methods are currently available for this address. Please contact us before ordering.</div><?php else:?><button class="button secondary">Save delivery method</button><?php endif;?></form>
<div class="review-total"><span>Cart</span><strong><?=money($summary['total_cents'])?></strong></div>
<?php if($loyaltyDiscount>0):?><div class="review-total discount"><span>Rewards · <?=(int)$loyaltyQuote['points']?> points</span><strong>−<?=money($loyaltyDiscount)?></strong></div><?php endif;?>
<div class="review-total"><span>Delivery</span><strong><?=$selected?($selected['price_cents']===0?'Free':money((int)$selected['price_cents'])):'—'?></strong></div>
<div class="review-total grand"><span>Total before tax</span><strong><?=money($grand)?></strong></div><?php if($taxSettings['automatic_tax_enabled']==='1' && $taxSettings['checkout_notice']!==''):?><div class="method-note"><?=htmlspecialchars($taxSettings['checkout_notice'])?></div><?php endif;?>
<?php if($loyaltyAccount):?><section class="gift-card-checkout"><h2>Rewards</h2><p class="muted">Available: <?=(int)$loyaltyAccount['available_points']?> points<?php if((int)$loyaltyAccount['points_balance']<0):?> · earn <?=abs((int)$loyaltyAccount['points_balance'])?> more before redeeming<?php endif;?></p><?php if($loyaltyDiscount>0):?><div class="review-total"><span><?=(int)$loyaltyQuote['points']?> points applied</span><strong>−<?=money($loyaltyDiscount)?></strong></div><form method="post"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="loyalty_remove"><button class="link">Remove rewards</button></form><?php elseif((int)$loyaltyAccount['available_points']>0):?><form method="post" class="coupon"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="loyalty_apply"><label class="sr-only" for="loyalty-points">Rewards points to redeem</label><input id="loyalty-points" type="number" min="1" max="<?=(int)$loyaltyAccount['available_points']?>" name="loyalty_points" inputmode="numeric" placeholder="Points to redeem" required><button class="button secondary">Apply rewards</button></form><?php else:?><p class="muted">Earn points on paid merchandise orders to unlock rewards.</p><?php endif;?></section><?php endif;?>
<section class="gift-card-checkout"><h2>Gift card</h2><?php if($giftCard):?><div class="review-total"><span>Card ending <?=htmlspecialchars($giftCard['code_last4'])?> · available <?=money((int)$giftCard['available_cents'])?></span><strong>Up to −<?=money(min((int)$giftCard['available_cents'],$grand))?></strong></div><form method="post"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="gift_card_remove"><button class="link">Remove gift card</button></form><?php else:?><form method="post" class="coupon"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="gift_card_apply"><label class="sr-only" for="gift-card-code">Gift card code</label><input id="gift-card-code" name="gift_card_code" autocomplete="off" placeholder="FDGC-XXXX-XXXX-XXXX-XXXX" required><button class="button secondary">Apply gift card</button></form><?php endif;?></section>
<div class="actions"><a class="button secondary" href="/checkout.php">Edit details</a><?php if($selected):?><form method="post" action="/pay.php"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><button class="button" type="submit">Pay securely with Stripe</button></form><?php endif;?></div>
</main></body></html>