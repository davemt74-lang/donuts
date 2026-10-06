<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';

use FudgeDonuts\{Database,FinishedGoodsAllocationService};

$db=Database::connection();
foreach(['001_catalog.sql','003_accounts.sql','006_orders.sql','013_admin_accounts.sql','037_batch_traceability.sql','039_ingredient_lot_traceability.sql','044_finished_goods_fefo.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));
$db->exec("INSERT INTO admin_users(email,password_hash,first_name,last_name,role) VALUES('alloc@example.com','x','Alloc','User','admin')");
$adminId=(int)$db->lastInsertId();$flavorId=(int)$db->query('SELECT id FROM flavors ORDER BY id LIMIT 1')->fetchColumn();

$insertBatch=$db->prepare("INSERT INTO production_batches(batch_code,flavor_id,produced_at,best_by_date,quantity_produced,quantity_remaining,status,created_by) VALUES(?,?,datetime('now','-1 day'),?,?,?,'active',?)");
$insertBatch->execute(['FEFO-EARLY',$flavorId,gmdate('Y-m-d',time()+86400),2,2,$adminId]);$early=(int)$db->lastInsertId();
$insertBatch->execute(['FEFO-LATE',$flavorId,gmdate('Y-m-d',time()+86400*5),5,5,$adminId]);$late=(int)$db->lastInsertId();

$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents) VALUES('FD-FEFO','fefo1','preparing','x@y.com','X','Y','1 Main','Phoenix','AZ','85001','standard','Standard','shipping',1000,1000)");
$orderId=(int)$db->lastInsertId();
$cfg=json_encode(['items'=>[['flavor_id'=>$flavorId,'name'=>'Flavor','quantity'=>4]]],JSON_THROW_ON_ERROR);
$item=$db->prepare("INSERT INTO order_items(order_id,kind,pack_size,quantity,unit_price_cents,line_total_cents,configuration_json) VALUES(?,?,?,?,?,?,?)");
$item->execute([$orderId,'custom',4,1,1000,1000,$cfg]);

$svc=new FinishedGoodsAllocationService($db);
$plan=$svc->plan($orderId);assert($plan['ok']===true);assert($plan['assignments'][$early]===2);assert($plan['assignments'][$late]===2);
$svc->assign($orderId,$adminId);
$assignments=$db->query("SELECT batch_id,quantity FROM order_batch_assignments WHERE order_id={$orderId} ORDER BY batch_id")->fetchAll();
assert(count($assignments)===2);assert((int)$db->query("SELECT quantity_remaining FROM production_batches WHERE id={$early}")->fetchColumn()===0);assert((int)$db->query("SELECT quantity_remaining FROM production_batches WHERE id={$late}")->fetchColumn()===3);
assert((int)$db->query("SELECT COUNT(*) FROM finished_goods_allocation_events WHERE order_id={$orderId} AND mode='fefo'")->fetchColumn()===1);

$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents) VALUES('FD-SHORT','short1','ready','x@y.com','X','Y','1 Main','Phoenix','AZ','85001','standard','Standard','shipping',1000,1000)");
$shortId=(int)$db->lastInsertId();$cfg2=json_encode(['items'=>[['flavor_id'=>$flavorId,'name'=>'Flavor','quantity'=>10]]],JSON_THROW_ON_ERROR);$item->execute([$shortId,'custom',10,1,1000,1000,$cfg2]);
$shortPlan=$svc->plan($shortId);assert($shortPlan['ok']===false);assert($shortPlan['shortages'][$flavorId]['unavailable']===7);
$blocked=false;try{$svc->assign($shortId,$adminId);}catch(InvalidArgumentException){$blocked=true;}assert($blocked);
assert((int)$db->query("SELECT COUNT(*) FROM order_batch_assignments WHERE order_id={$shortId}")->fetchColumn()===0);
assert((int)$db->query("SELECT quantity_remaining FROM production_batches WHERE id={$late}")->fetchColumn()===3);

$db->exec("INSERT INTO production_batches(batch_code,flavor_id,produced_at,best_by_date,quantity_produced,quantity_remaining,status,created_by) VALUES('HELD-BATCH',{$flavorId},datetime('now','-2 day'),date('now','+1 day'),20,20,'hold',{$adminId})");
$shortPlan2=$svc->plan($shortId);assert($shortPlan2['shortages'][$flavorId]['unavailable']===7);

$summary=$svc->summary();assert($summary['assigned']===1);assert($summary['shortage']===1);assert($summary['waiting']===1);
echo "Section 74 checks passed\n";
