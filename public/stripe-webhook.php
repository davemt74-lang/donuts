<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{AnalyticsService,CostAccountingService,Database,GiftCardService,InventoryService,LoyaltyService,NotificationService,OrderService,PaymentRepository,PromotionService,StripeService,TaxService};

$payload=file_get_contents('php://input')?:'';
$signature=(string)($_SERVER['HTTP_STRIPE_SIGNATURE']??'');
$stripe=new StripeService((string)env('STRIPE_SECRET_KEY',''),(string)env('STRIPE_WEBHOOK_SECRET',''));
if(!$stripe->verifyWebhook($payload,$signature)){http_response_code(400);exit('Invalid signature');}
$event=json_decode($payload,true);
if(!is_array($event) || empty($event['id']) || empty($event['type'])){http_response_code(400);exit('Invalid event');}

$db=Database::connection();
$payments=new PaymentRepository($db);
$isNew=$payments->recordEvent('stripe',(string)$event['id'],(string)$event['type'],$payload);
if(!$isNew){http_response_code(200);echo 'ok';exit;}

$object=$event['data']['object']??[];
$orderService=new OrderService($db);$giftCards=new GiftCardService($db,(string)env('APP_KEY',''));$loyalty=new LoyaltyService($db);
if(is_array($object) && !empty($object['id'])){
    $giftPurchase=$giftCards->purchaseByStripeSession((string)$object['id']);
    if($giftPurchase){
        if($event['type']==='checkout.session.completed' && ($object['payment_status']??'')==='paid'){
            $issued=$giftCards->activatePurchase((int)$giftPurchase['id'],(string)$object['id'],(int)($object['amount_total']??0),strtolower((string)($object['currency']??'')));
            $notifications=new NotificationService($db);
            $recipientBody="You received a Fudge Donuts gift card.\n\nAmount: ".money((int)$issued['card']['initial_balance_cents'])."\nGift card code: ".$issued['code'];
            if(trim((string)$issued['purchase']['message'])!=='')$recipientBody.="\n\nMessage:\n".$issued['purchase']['message'];
            $notifications->queue((string)$issued['purchase']['recipient_email'],'Your Fudge Donuts gift card',$recipientBody,'gift-card-delivery:'.$issued['purchase']['id']);
            $notifications->queue((string)$issued['purchase']['purchaser_email'],'Your Fudge Donuts gift card purchase is complete',"Your ".money((int)$issued['card']['initial_balance_cents'])." gift card has been issued to ".$issued['purchase']['recipient_email'].".",'gift-card-receipt:'.$issued['purchase']['id']);
        }elseif(in_array($event['type'],['checkout.session.expired','checkout.session.async_payment_failed'],true)){
            $giftCards->failPurchaseByStripeSession((string)$object['id']);
        }
        $payments->markEventProcessed('stripe',(string)$event['id']);http_response_code(200);echo 'ok';exit;
    }
    if($event['type']==='checkout.session.completed' && ($object['payment_status']??'')==='paid'){
        $subtotal=(int)($object['amount_subtotal']??0);
        $total=(int)($object['amount_total']??0);
        $tax=(int)($object['total_details']['amount_tax']??0);
        $currency=strtolower((string)($object['currency']??''));
        $orderId=$orderService->idByStripeSession((string)$object['id']);
        $orderService->attachStripePaymentIntent((string)$object['id'],(string)($object['payment_intent']??''));
        $application=$orderId?$giftCards->applicationForOrder($orderId):null;
        if($application && $application['status']==='reserved'){
            $calculatedTax=(new TaxService($db))->calculatedForOrder($orderId);
            $matched=$orderService->markPaidByExternalTender((string)$object['id'],$total,(int)$application['reserved_cents'],$calculatedTax,$currency);
        }else{
            $matched=$orderService->markPaidByStripeSession((string)$object['id'],$subtotal,$total,$tax,$currency);
        }
        if($matched){
            $payments->markCompletedByProviderSession((string)$object['id']);
            if($orderId){
                try{$loyalty->commitRedemption($orderId);}
                catch(Throwable $e){
                    $orderService->markSettlementReview($orderId,'Rewards redemption failed after Stripe settlement.');
                    (new InventoryService($db))->holdForReview($orderId);
                    \FudgeDonuts\ObservabilityService::captureThrowable($e,dirname(__DIR__),'loyalty_redemption_failure');
                    $payments->markReviewByProviderSession((string)$object['id']);
                    $payments->markEventProcessed('stripe',(string)$event['id']);http_response_code(200);echo 'ok';exit;
                }
                $taxService=new TaxService($db);
                if($application && $application['status']==='reserved'){
                    try{$giftCards->redeemForOrder($orderId);}
                    catch(Throwable $e){
                        $orderService->markSettlementReview($orderId,'Gift card redemption failed after Stripe settlement.');
                        (new InventoryService($db))->holdForReview($orderId);
                        \FudgeDonuts\ObservabilityService::captureThrowable($e,dirname(__DIR__),'gift_card_redemption_failure');
                        $payments->markReviewByProviderSession((string)$object['id']);
                        $payments->markEventProcessed('stripe',(string)$event['id']);http_response_code(200);echo 'ok';exit;
                    }
                    $taxService->recordCollected($orderId,$taxService->calculatedForOrder($orderId));
                }else{
                    $taxService->recordCollected($orderId,$tax);
                }
                (new InventoryService($db))->commitOrder($orderId);
                (new PromotionService($db))->redeemOrder($orderId);
                (new NotificationService($db))->queueOrderConfirmation($orderService->find($orderId));
                try{$loyalty->earnForOrder($orderId);}catch(Throwable $e){\FudgeDonuts\ObservabilityService::captureThrowable($e,dirname(__DIR__),'loyalty_earn_failure');}
                try{(new CostAccountingService($db))->snapshotOrder($orderId);}catch(Throwable $e){
                    \FudgeDonuts\ObservabilityService::captureThrowable($e,dirname(__DIR__),'cost_snapshot_failure');
                }
                if((env('ANALYTICS_ENABLED','0')??'0')==='1'){
                    try{(new AnalyticsService($db))->recordPurchase($orderId);}catch(Throwable){}
                }
            }
        }else{
            $payments->markReviewByProviderSession((string)$object['id']);
            if($orderId)(new InventoryService($db))->holdForReview($orderId);
        }
    }elseif(in_array($event['type'],['checkout.session.expired','checkout.session.async_payment_failed'],true)){
        $orderId=$orderService->idByStripeSession((string)$object['id']);
        $payments->markFailedByProviderSession((string)$object['id']);
        $orderService->markPaymentFailedByStripeSession((string)$object['id'],(string)$event['type']);
        if($orderId){
            (new InventoryService($db))->releaseOrder($orderId);
            $giftCards->releaseForOrder($orderId);
            $loyalty->releaseForOrder($orderId);
        }
    }
}
$payments->markEventProcessed('stripe',(string)$event['id']);
http_response_code(200);echo 'ok';
