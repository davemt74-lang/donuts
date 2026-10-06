<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{Database,InventoryService,NotificationService,OrderService,PaymentRepository,StripeService};

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
$orderService=new OrderService($db);
if(is_array($object) && !empty($object['id'])){
    if($event['type']==='checkout.session.completed' && ($object['payment_status']??'')==='paid'){
        $total=(int)($object['amount_total']??0);
        $tax=(int)($object['total_details']['amount_tax']??0);
        $orderId=$orderService->idByStripeSession((string)$object['id']);
        $orderService->markPaidByStripeSession((string)$object['id'],$total,$tax);
        if($orderId){
            (new InventoryService($db))->commitOrder($orderId);
            (new NotificationService($db))->queueOrderConfirmation($orderService->find($orderId));
        }
    }elseif(in_array($event['type'],['checkout.session.expired','checkout.session.async_payment_failed'],true)){
        $orderId=$orderService->idByStripeSession((string)$object['id']);
        $orderService->markPaymentFailedByStripeSession((string)$object['id'],(string)$event['type']);
        if($orderId)(new InventoryService($db))->releaseOrder($orderId);
    }
}
$payments->markEventProcessed('stripe',(string)$event['id']);
http_response_code(200);echo 'ok';
