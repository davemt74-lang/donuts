<?php
declare(strict_types=1);$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{Database,NotificationService};
$db=Database::connection();$db->exec((string)file_get_contents($root.'/database/008_notifications.sql'));$n=new NotificationService($db);
$order=['id'=>1,'order_number'=>'FD-1','first_name'=>'Dave','email'=>'d@example.com','total_cents'=>4200,'fulfillment_name'=>'Local Pickup'];
$n->queueOrderConfirmation($order);$n->queueOrderConfirmation($order);assert(count($n->pending())===1);$n->markSent((int)$n->pending()[0]['id']);assert(count($n->pending())===0);
$n->queueStatusUpdate($order,'preparing');assert(count($n->pending())===1);
echo "Section 12 checks passed\n";
