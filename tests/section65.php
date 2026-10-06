<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{BatchTraceabilityService,Database};

$db=Database::connection();
foreach(['001_catalog.sql','003_accounts.sql','006_orders.sql','013_admin_accounts.sql','036_batch_traceability.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));
$db->exec("INSERT INTO admin_users(email,password_hash,first_name,last_name,role) VALUES('admin@example.com','x','A','D','admin')");$aid=(int)$db->lastInsertId();
$f1=(int)$db->query('SELECT id FROM flavors ORDER BY id LIMIT 1')->fetchColumn();$f2=(int)$db->query('SELECT id FROM flavors ORDER BY id LIMIT 1 OFFSET 1')->fetchColumn();
$svc=new BatchTraceabilityService($db);
$batch=$svc->create(['batch_code'=>'FD-20261006-A','produced_at'=>'2026-10-06 08:00:00','best_by_date'=>'2026-10-20','notes'=>'Morning run','flavor_quantity'=>[$f1=>24,$f2=>12]],$aid);
assert($batch>0);assert($svc->batch($batch)['status']==='draft');assert(count($svc->batch($batch)['flavors'])===2);
$svc->setStatus($batch,'released');assert($svc->batch($batch)['status']==='released');

$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents) VALUES('FD-BATCH','batchorder','preparing','buyer@example.com','Buyer','One','1 Main','Phoenix','AZ','85001','standard','Standard','shipping',1000,1000)");
$order=(int)$db->lastInsertId();
$svc->assignOrder($order,$batch,$aid);assert(count($svc->batchesForOrder($order))===1);assert(count($svc->affectedOrders($batch))===1);
$svc->setStatus($batch,'recalled','Packaging seal issue');$b=$svc->batch($batch);assert($b['status']==='recalled');assert($b['recall_reason']==='Packaging seal issue');assert(count($svc->recallNotificationTargets($batch))===1);

$db->exec("UPDATE orders SET status='shipped' WHERE id={$order}");
$blocked=false;try{$svc->removeOrderBatch($order,$batch);}catch(InvalidArgumentException){$blocked=true;}assert($blocked);

$bad=false;try{$svc->create(['batch_code'=>'bad code','produced_at'=>'2026-10-06','flavor_quantity'=>[$f1=>1]],$aid);}catch(InvalidArgumentException){$bad=true;}assert($bad);
echo "Section 65 checks passed\n";
