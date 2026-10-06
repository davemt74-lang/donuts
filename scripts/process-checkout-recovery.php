<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{CheckoutRecoveryService,Database,JobMonitorService,MarketingConsentService,NotificationService};

$db=Database::connection();$monitor=new JobMonitorService($db);$run=$monitor->start('checkout-recovery','Consent-safe abandoned checkout recovery',(int)env('JOB_CHECKOUT_RECOVERY_INTERVAL_MINUTES','60'));
$queued=0;$expired=0;
try{
    $svc=new CheckoutRecoveryService($db,(string)env('APP_KEY',''),(string)env('APP_URL','http://127.0.0.1:8080'),(int)env('CHECKOUT_RECOVERY_DAYS','7'));
    $notifications=new NotificationService($db);
    $marketing=new MarketingConsentService($db,(string)env('APP_KEY',''),(string)env('APP_URL','http://127.0.0.1:8080'));
    $expired=$svc->expire();
    foreach($svc->dueForReminder((int)env('CHECKOUT_RECOVERY_REMINDER_HOURS','2')) as $row){
        $link=$svc->link($row);
        $unsubscribe=$marketing->unsubscribeLink((string)$row['email']);
        $body="You left a Fudge Donuts box in checkout. If you still want it, reopen your cart here:\n\n{$link}\n\nPrices, availability, discounts, shipping and tax are recalculated when you return.\n\nUnsubscribe from marketing emails: {$unsubscribe}";
        $notifications->queue((string)$row['email'],'Still thinking about your Fudge Donuts box?',$body,'checkout-recovery:'.$row['id']);
        $svc->markReminderSent((int)$row['id']);$queued++;
    }
    $monitor->succeed($run,"queued={$queued} expired={$expired}");
    echo "Checkout recovery: queued={$queued} expired={$expired}\n";
}catch(Throwable $e){$monitor->fail($run,$e->getMessage());throw $e;}
