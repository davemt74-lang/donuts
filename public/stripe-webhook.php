<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{Database,OrderService,PaymentRepository,StripeService};

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
        $orderService->markPaidByStripeSession((string)$object['id'],$total,$tax);
    }elseif(in_array($event['type'],['checkout.session.expired','checkout.session.async_payment_failed'],true)){
        $orderService->markPaymentFailedByStripeSession((string)$object['id'],(string)$event['type']);
    }
}
http_response_code(200);echo 'ok';
