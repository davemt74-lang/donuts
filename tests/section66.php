<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{BatchTraceabilityService,Database};

$db=Database::connection();
foreach(['001_catalog.sql','006_orders.sql','013_admin_accounts.sql','037_batch_traceability.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));
$db->exec("INSERT INTO admin_users(email,password_hash,first_name,last_name,role) VALUES('admin@example.com','x','A','D','admin')");$aid=(int)$db->lastInsertId();
$fids=array_map('intval',$db->query('SELECT id FROM flavors ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN));
$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents) VALUES('FD-LOT','lot1','preparing','buyer@example.com','Buyer','One','1 Main','Phoenix','AZ','85001','standard','Standard','shipping',2000,2000)");
$orderId=(int)$db->lastInsertId();
$cfg=json_encode(['name'=>'Custom Box','items'=>[['flavor_id'=>$fids[0],'name'=>'One','quantity'=>2],['flavor_id'=>$fids[1],'name'=>'Two','quantity'=>1]]],JSON_THROW_ON_ERROR);
$s=$db->prepare("INSERT INTO order_items(order_id,kind,pack_size,quantity,unit_price_cents,line_total_cents,configuration_json) VALUES(?,?,?,?,?,?,?)");$s->execute([$orderId,'custom',3,2,1000,2000,$cfg]);

$svc=new BatchTraceabilityService($db);
$b1=$svc->createBatch(['batch_code'=>'LOT-A1','flavor_id'=>$fids[0],'produced_at'=>'2026-10-06 08:00:00','best_by_date'=>'2026-10-13','quantity_produced'=>20],$aid);
$b2=$svc->createBatch(['batch_code'=>'LOT-B1','flavor_id'=>$fids[1],'produced_at'=>'2026-10-06 08:00:00','best_by_date'=>'2026-10-13','quantity_produced'=>20],$aid);
assert($svc->requiredFlavorQuantities($orderId)===[$fids[0]=>4,$fids[1]=>2]);
$svc->assignOrder($orderId,[$b1=>4,$b2=>2],$aid);assert(count($svc->assignmentsForOrder($orderId))===2);
assert((int)$svc->batch($b1)['quantity_remaining']===16);
$affected=$svc->recall($b1,'Quality issue',$aid);assert(count($affected)===1);assert($affected[0]['email']==='buyer@example.com');assert($svc->batch($b1)['status']==='recalled');
$bad=false;try{$svc->assignOrder($orderId,[$b2=>6],$aid);}catch(InvalidArgumentException){$bad=true;}assert($bad);
echo "Section 66 checks passed\n";
