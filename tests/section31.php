<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{Database,EmailDeliveryService,NotificationService};
$db=Database::connection();foreach(['008_notifications.sql','019_notification_email.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));
$n=new NotificationService($db);
$order=['id'=>44,'order_number'=>'FD-44','first_name'=>'Dave','email'=>'d@example.com','total_cents'=>4200,'fulfillment_name'=>'Local Pickup','status'=>'ready'];
$n->queueOrderConfirmation($order);$pending=$n->pending();assert(count($pending)===1);assert(str_contains($pending[0]['html_body'],'Order confirmed'));
$mailer=new EmailDeliveryService('log','orders@example.com','Fudge Donuts');
$mime=$mailer->buildMime($pending[0]);assert(str_contains($mime['headers'],'multipart/alternative'));assert(str_contains($mime['body'],'text/html'));
for($i=0;$i<5;$i++)$n->markFailed((int)$pending[0]['id'],'temporary failure');
$status=$db->query('SELECT status FROM notification_outbox WHERE id='.(int)$pending[0]['id'])->fetchColumn();assert($status==='failed');
$n->queuePasswordReset('d@example.com','Dave','https://example.com/reset?x=1','2026-10-06 04:00:00');$rows=$db->query("SELECT c.html_body FROM notification_email_content c JOIN notification_outbox o ON o.id=c.outbox_id WHERE o.subject LIKE 'Reset%'")->fetchAll();assert(count($rows)===1);assert(str_contains($rows[0]['html_body'],'Reset password'));
echo "Section 31 checks passed\n";
