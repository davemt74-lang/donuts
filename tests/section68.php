<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{BatchTraceabilityService,Database,IngredientTraceabilityService};

$db=Database::connection();
foreach(['001_catalog.sql','006_orders.sql','013_admin_accounts.sql','037_batch_traceability.sql','038_batch_traceability_hardening.sql','039_ingredient_lot_traceability.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));
$db->exec("INSERT INTO admin_users(email,password_hash,first_name,last_name,role) VALUES('admin@example.com','x','A','D','admin')");$aid=(int)$db->lastInsertId();
$fid=(int)$db->query('SELECT id FROM flavors ORDER BY id LIMIT 1')->fetchColumn();$produced=gmdate('Y-m-d H:i:s',time()-3600);$best=gmdate('Y-m-d',time()+7*86400);
$batchSvc=new BatchTraceabilityService($db);$batch=$batchSvc->createBatch(['batch_code'=>'ING-BATCH','flavor_id'=>$fid,'produced_at'=>$produced,'best_by_date'=>$best,'quantity_produced'=>20],$aid);

$svc=new IngredientTraceabilityService($db);
$lot=$svc->createLot(['ingredient_name'=>'Cocoa','supplier_name'=>'Supplier A','supplier_lot_code'=>'COCOA-1','received_at'=>gmdate('Y-m-d H:i:s',time()-86400),'best_by_date'=>gmdate('Y-m-d',time()+30*86400),'quantity_received'=>25,'quantity_unit'=>'lb'],$aid);
$svc->linkBatch($batch,$lot,4.5,'lb',$aid);assert(count($svc->ingredientsForBatch($batch))===1);assert($svc->batchRisk($batch)['ok']===true);assert($svc->summary()['unlinked_batches']===0);

$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents) VALUES('FD-ING','ing1','preparing','buyer@example.com','Buyer','One','1 Main','Phoenix','AZ','85001','standard','Standard','shipping',1000,1000)");$order=(int)$db->lastInsertId();
$cfg=json_encode(['name'=>'One','items'=>[['flavor_id'=>$fid,'name'=>'One','quantity'=>1]]],JSON_THROW_ON_ERROR);$s=$db->prepare("INSERT INTO order_items(order_id,kind,pack_size,quantity,unit_price_cents,line_total_cents,configuration_json) VALUES(?,?,?,?,?,?,?)");$s->execute([$order,'custom',1,1,1000,1000,$cfg]);
$batchSvc->assignOrder($order,[$batch=>1],$aid);assert(count($svc->affectedOrders($lot))===1);assert($batchSvc->orderShipmentReady($order)['ok']===true);

$svc->setHold($lot,true);assert($svc->batchRisk($batch)['ok']===false);assert($batchSvc->orderShipmentReady($order)['ok']===false);
$svc->setHold($lot,false);assert($svc->batchRisk($batch)['ok']===true);assert($batchSvc->orderShipmentReady($order)['ok']===true);
$affected=$svc->recall($lot,'Supplier recall',$aid);assert(count($affected)===1);assert($svc->batchRisk($batch)['ok']===false);assert($batchSvc->orderShipmentReady($order)['ok']===false);

$db->exec("UPDATE orders SET status='shipped' WHERE id={$order}");$immutable=false;try{$svc->unlinkBatch($batch,$lot);}catch(InvalidArgumentException){$immutable=true;}assert($immutable);

$future=false;try{$svc->createLot(['ingredient_name'=>'Sugar','supplier_name'=>'Supplier B','supplier_lot_code'=>'FUTURE','received_at'=>gmdate('Y-m-d H:i:s',time()+86400)],$aid);}catch(InvalidArgumentException){$future=true;}assert($future);
$badDate=false;try{$svc->createLot(['ingredient_name'=>'Sugar','supplier_name'=>'Supplier B','supplier_lot_code'=>'BAD-DATE','received_at'=>gmdate('Y-m-d H:i:s',time()-86400),'best_by_date'=>gmdate('Y-m-d',time()-2*86400)],$aid);}catch(InvalidArgumentException){$badDate=true;}assert($badDate);

$expired=$svc->createLot(['ingredient_name'=>'Sugar','supplier_name'=>'Supplier B','supplier_lot_code'=>'EXPIRED','received_at'=>gmdate('Y-m-d H:i:s',time()-10*86400),'best_by_date'=>gmdate('Y-m-d',time()-2*86400)],$aid);
$svc->setHold($expired,true);$expiredRelease=false;try{$svc->setHold($expired,false);}catch(InvalidArgumentException){$expiredRelease=true;}assert($expiredRelease);

$duplicate=false;try{$svc->createLot(['ingredient_name'=>'Cocoa','supplier_name'=>'Supplier A','supplier_lot_code'=>'COCOA-1','received_at'=>gmdate('Y-m-d H:i:s',time()-86400)],$aid);}catch(InvalidArgumentException){$duplicate=true;}assert($duplicate);

echo "Section 68 checks passed\n";
