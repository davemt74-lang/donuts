<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{Database,EmailDeliveryService,JobMonitorService,NotificationService};

$db=Database::connection();$monitor=new JobMonitorService($db);
$runId=$monitor->start('notifications','Transactional email delivery',(int)env('JOB_NOTIFICATIONS_INTERVAL_MINUTES','5'));
$sent=0;$failed=0;
try{
    $svc=new NotificationService($db);
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
            $mailer->send($message);$svc->markSent((int)$message['id']);$sent++;
        }catch(Throwable $e){
            $svc->markFailed((int)$message['id'],$e->getMessage());$failed++;
        }
    }
    $monitor->succeed($runId,"sent={$sent} failed={$failed}");
}catch(Throwable $e){
    $monitor->fail($runId,$e->getMessage());throw $e;
}
