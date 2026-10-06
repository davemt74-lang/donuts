<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{Database,EmailDeliveryService,NotificationService};

$svc=new NotificationService(Database::connection());
$mailer=new EmailDeliveryService(
    strtolower((string)env('MAIL_TRANSPORT','log')),
    (string)env('MAIL_FROM','orders@example.com'),
    (string)env('MAIL_FROM_NAME','Fudge Donuts'),
    (string)env('SMTP_HOST',''),
    (int)env('SMTP_PORT','587'),
    (string)env('SMTP_USERNAME',''),
    (string)env('SMTP_PASSWORD',''),
    strtolower((string)env('SMTP_ENCRYPTION','tls'))
);
foreach($svc->pending() as $message){
    try{
        $mailer->send($message);
        $svc->markSent((int)$message['id']);
    }catch(Throwable $e){
        $svc->markFailed((int)$message['id'],$e->getMessage());
    }
}
