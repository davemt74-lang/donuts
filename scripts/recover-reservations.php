<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{AnalyticsService,CostAccountingService,Database,InventoryService,JobMonitorService,NotificationService,OrderService,PaymentRepository,PromotionService,StripeService,TaxService};

$db=Database::connection();
$monitor=new JobMonitorService($db);$runId=$monitor->start('reservations','Abandoned checkout reservation recovery',(int)env('JOB_RESERVATIONS_INTERVAL_MINUTES','5'));
$inventory=new InventoryService($db);$giftCards=new GiftCardService($db,(string)env('APP_KEY',''));
$orders=new OrderService($db);
$payments=new PaymentRepository($db);
$stripe=new StripeService((string)env('STRIPE_SECRET_KEY',''),(string)env('STRIPE_WEBHOOK_SECRET',''));

$released=0;$committed=0;$held=0;$errors=0;
foreach($inventory->expiredLeases(200) as $lease){
    $orderId=(int)$lease['order_id'];
    $status=(string)$lease['order_status'];
    $sessionId=trim((string)($lease['stripe_checkout_session_id']??''));
    try{
        if($status==='paid'){
            $inventory->commitOrder($orderId);$committed++;continue;
        }
        if($status==='payment_review'){
            $inventory->holdForReview($orderId);$held++;continue;
        }
        if(in_array($status,['cancelled','refunded','payment_failed'],true)){
            $inventory->releaseOrder($orderId);$giftCards->releaseForOrder($orderId);$released++;continue;
        }
        if($status!=='pending_payment'){
            fwrite(STDERR,"[skip] order {$orderId} has status {$status}\n");continue;
        }

        if($sessionId===''){
            $orders->markPaymentInitializationFailed($orderId,'Inventory reservation expired before Stripe Checkout was attached.');
            $inventory->releaseOrder($orderId);$giftCards->releaseForOrder($orderId);$released++;continue;
        }

        $session=$stripe->retrieveCheckoutSession($sessionId);
        $stripeStatus=(string)($session['status']??'');
        $paymentStatus=(string)($session['payment_status']??'');

        if($stripeStatus==='complete' && $paymentStatus==='paid'){
            $orders->attachStripePaymentIntent($sessionId,(string)($session['payment_intent']??''));
            $matched=$orders->markPaidByStripeSession(
                $sessionId,
                (int)($session['amount_subtotal']??0),
                (int)($session['amount_total']??0),
                (int)($session['total_details']['amount_tax']??0),
                (string)($session['currency']??'')
            );
            if($matched){
                $payments->markCompletedByProviderSession($sessionId);
                (new TaxService($db))->recordCollected($orderId,(int)($session['total_details']['amount_tax']??0));
                $inventory->commitOrder($orderId);
                (new PromotionService($db))->redeemOrder($orderId);
                try{(new CostAccountingService($db))->snapshotOrder($orderId);}catch(Throwable $e){\FudgeDonuts\ObservabilityService::captureThrowable($e,dirname(__DIR__),'cost_snapshot_failure');}
                if((env('ANALYTICS_ENABLED','0')??'0')==='1'){try{(new AnalyticsService($db))->recordPurchase($orderId);}catch(Throwable){}}
                (new NotificationService($db))->queueOrderConfirmation($orders->find($orderId));
                $committed++;
            }else{
                $payments->markReviewByProviderSession($sessionId);
                $inventory->holdForReview($orderId);$held++;
            }
            continue;
        }

        if($stripeStatus==='open'){
            $stripe->expireCheckoutSession($sessionId);
            $payments->markFailedByProviderSession($sessionId);
            $orders->markPaymentFailedByStripeSession($sessionId,'Local inventory reservation expired.');
            $inventory->releaseOrder($orderId);$released++;continue;
        }

        if($stripeStatus==='expired'){
            $payments->markFailedByProviderSession($sessionId);
            $orders->markPaymentFailedByStripeSession($sessionId,'Stripe Checkout session expired.');
            $inventory->releaseOrder($orderId);$released++;continue;
        }

        fwrite(STDERR,"[hold] order {$orderId} Stripe status {$stripeStatus}/{$paymentStatus} requires another check\n");
        $errors++;
    }catch(Throwable $e){
        fwrite(STDERR,"[error] order {$orderId}: {$e->getMessage()}\n");
        $errors++;
    }
}
$summary="released={$released} committed={$committed} review_holds={$held} deferred={$errors}";
if($errors>0)$monitor->fail($runId,$summary);else $monitor->succeed($runId,$summary);
fwrite(STDOUT,"Reservation recovery: {$summary}\n");
exit($errors>0?2:0);
