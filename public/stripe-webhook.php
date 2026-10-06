<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\StripeService;

$payload=file_get_contents('php://input')?:'';
$signature=(string)($_SERVER['HTTP_STRIPE_SIGNATURE']??'');
$stripe=new StripeService((string)env('STRIPE_SECRET_KEY',''),(string)env('STRIPE_WEBHOOK_SECRET',''));
if(!$stripe->verifyWebhook($payload,$signature)){http_response_code(400);exit('Invalid signature');}
$event=json_decode($payload,true);
if(!is_array($event) || empty($event['type'])){http_response_code(400);exit('Invalid event');}

/*
 * Durable payment/order state transitions are handled by the order service
 * once the order tables are introduced. This endpoint verifies authenticity
 * now and intentionally rejects unsupported event handling silently.
 */
http_response_code(200);
echo 'ok';
