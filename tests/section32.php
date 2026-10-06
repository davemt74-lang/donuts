<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{Database,NotificationService};
$db=Database::connection();foreach(['008_notifications.sql','019_notification_email.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));
$n=new NotificationService($db);
$n->queue('a@example.com','A','body','a','<p>A</p>');
$n->queue('b@example.com','B','body','b','<p>B</p>');
$n->queue('c@example.com','C','body','c','<p>C</p>');
$rows=$n->recent();assert(count($rows)===3);
$n->markSent((int)$rows[2]['id']);
for($i=0;$i<5;$i++)$n->markFailed((int)$rows[1]['id'],'smtp down');
$stats=$n->stats();assert($stats['sent']===1);assert($stats['failed']===1);assert($stats['pending']===1);assert($stats['total']===3);
$failed=$n->recent(100,'failed');assert(count($failed)===1);assert(str_contains($failed[0]['last_error'],'smtp down'));
$n->retry((int)$failed[0]['id']);$stats=$n->stats();assert($stats['failed']===0);assert($stats['pending']===2);
for($i=0;$i<5;$i++)$n->markFailed((int)$rows[1]['id'],'again');$count=$n->retryAllFailed();assert($count===1);assert($n->stats()['failed']===0);
$bad=false;try{$n->recent(10,'bogus');}catch(InvalidArgumentException){$bad=true;}assert($bad);
echo "Section 32 checks passed\n";
