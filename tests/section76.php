<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{Database,FinishedGoodsReplenishmentService};

$db=Database::connection();
foreach(['001_catalog.sql','003_accounts.sql','006_orders.sql','013_admin_accounts.sql','037_batch_traceability.sql','042_production_scheduling.sql','046_finished_goods_replenishment.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));
$db->exec("INSERT INTO flavors(slug,name,description,active,sort_order) VALUES('choc','Chocolate','Chocolate',1,1)");$flavor=(int)$db->lastInsertId();
$db->exec("INSERT INTO admin_users(email,password_hash,first_name,last_name,role) VALUES('admin@example.com','x','A','D','admin')");$admin=(int)$db->lastInsertId();
$cfg=json_encode(['items'=>[['flavor_id'=>$flavor,'name'=>'Chocolate','quantity'=>6]]],JSON_THROW_ON_ERROR);
$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents,created_at) VALUES('FD-R1','r1','paid','x@y.com','X','Y','1','P','AZ','85001','standard','Standard','shipping',1000,1000,datetime('now','-1 day'))");
$order=(int)$db->lastInsertId();$s=$db->prepare("INSERT INTO order_items(order_id,kind,pack_size,quantity,unit_price_cents,line_total_cents,configuration_json) VALUES(?,?,?,?,?,?,?)");$s->execute([$order,'custom',6,1,1000,1000,$cfg]);
$db->exec("INSERT INTO production_batches(batch_code,flavor_id,produced_at,best_by_date,quantity_produced,quantity_remaining,status) VALUES('B-OLD',{$flavor},datetime('now','-1 day'),date('now','+5 days'),2,2,'active')");
$svc=new FinishedGoodsReplenishmentService($db);$plan=$svc->recommendations();$row=$plan['rows'][0];
assert($row['open_demand']===6);assert($row['safe_stock']===2);assert($row['recommended_units']>=4);assert($row['risk']==='critical');
$svc->saveSettings(28,0,6);$plan=$svc->recommendations();assert($plan['rows'][0]['recommended_units']===6);
$work=$svc->createWorkOrder($flavor,6,gmdate('Y-m-d'),$admin);assert($work>0);assert(count($svc->events())===1);
$plan=$svc->recommendations();assert($plan['rows'][0]['scheduled_units']===6);assert($plan['rows'][0]['recommended_units']===0);
echo "Section 76 checks passed\n";
