<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{Database,ProductionPlanningService};

$db=Database::connection();
foreach(['001_catalog.sql','006_orders.sql','007_inventory.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));
$f1=(int)$db->query("SELECT id FROM flavors ORDER BY id LIMIT 1")->fetchColumn();
$f2=(int)$db->query("SELECT id FROM flavors ORDER BY id LIMIT 1 OFFSET 1")->fetchColumn();
$db->prepare("UPDATE flavor_inventory SET track_inventory=1,stock_on_hand=5,reserved=1,low_stock_threshold=2 WHERE flavor_id=?")->execute([$f1]);
$db->prepare("UPDATE flavor_inventory SET track_inventory=0 WHERE flavor_id=?")->execute([$f2]);
$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents) VALUES('FD-P1','p1','paid','x@y.com','X','Y','1','Phoenix','AZ','85001','standard','Standard','shipping',1000,1000)");
$order=(int)$db->lastInsertId();
$cfg=json_encode(['name'=>'Test Box','items'=>[['flavor_id'=>$f1,'name'=>'Flavor 1','quantity'=>28],['flavor_id'=>$f2,'name'=>'Flavor 2','quantity'=>14]]],JSON_THROW_ON_ERROR);
$s=$db->prepare("INSERT INTO order_items(order_id,kind,pack_size,quantity,unit_price_cents,line_total_cents,configuration_json) VALUES(?,?,?,?,?,?,?)");
$s->execute([$order,'custom',42,1,1000,1000,$cfg]);
$plan=(new ProductionPlanningService($db))->forecast(28,7,2);
$r1=array_values(array_filter($plan['rows'],fn($r)=>$r['flavor_id']===$f1))[0];
$r2=array_values(array_filter($plan['rows'],fn($r)=>$r['flavor_id']===$f2))[0];
assert($r1['recent_units']===28);assert($r1['daily_velocity']===1.0);assert($r1['forecast_units']===7);assert($r1['safety_units']===2);assert($r1['available']===4);assert($r1['suggested_prep']===5);assert($r1['days_cover']===4.0);assert($r1['risk']==='medium');
assert($r2['recent_units']===14);assert($r2['tracked']===false);assert($r2['forecast_units']===4);assert($r2['safety_units']===1);assert($r2['suggested_prep']===5);assert($r2['risk']==='untracked');
assert($plan['totals']['suggested_prep']>=10);
echo "Section 57 checks passed\n";
