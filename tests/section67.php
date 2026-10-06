<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{BatchTraceabilityService,Database};

$db=Database::connection();
foreach(['001_catalog.sql','006_orders.sql','013_admin_accounts.sql','037_batch_traceability.sql','038_batch_traceability_hardening.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));
$db->exec("INSERT INTO admin_users(email,password_hash,first_name,last_name,role) VALUES('admin@example.com','x','A','D','admin')");$aid=(int)$db->lastInsertId();
$fids=array_map('intval',$db->query('SELECT id FROM flavors ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN));
$produced=gmdate('Y-m-d H:i:s',time()-3600);$bestBy=gmdate('Y-m-d',time()+7*86400);

$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents) VALUES('FD-HARD','hard1','preparing','buyer@example.com','Buyer','One','1 Main','Phoenix','AZ','85001','standard','Standard','shipping',2000,2000)");
$orderId=(int)$db->lastInsertId();
$cfg=json_encode(['name'=>'Custom Box','items'=>[['flavor_id'=>$fids[0],'name'=>'One','quantity'=>2],['flavor_id'=>$fids[1],'name'=>'Two','quantity'=>1]]],JSON_THROW_ON_ERROR);
$s=$db->prepare("INSERT INTO order_items(order_id,kind,pack_size,quantity,unit_price_cents,line_total_cents,configuration_json) VALUES(?,?,?,?,?,?,?)");$s->execute([$orderId,'custom',3,2,1000,2000,$cfg]);

$svc=new BatchTraceabilityService($db);
$b1=$svc->createBatch(['batch_code'=>'HARD-A','flavor_id'=>$fids[0],'produced_at'=>$produced,'best_by_date'=>$bestBy,'quantity_produced'=>20],$aid);
$b2=$svc->createBatch(['batch_code'=>'HARD-B','flavor_id'=>$fids[1],'produced_at'=>$produced,'best_by_date'=>$bestBy,'quantity_produced'=>20],$aid);
$svc->assignOrder($orderId,[$b1=>4,$b2=>2],$aid);
assert((int)$svc->batch($b1)['quantity_remaining']===16);
$svc->unassignOrder($orderId);assert((int)$svc->batch($b1)['quantity_remaining']===20);assert(count($svc->assignmentsForOrder($orderId))===0);
$svc->assignOrder($orderId,[$b1=>4,$b2=>2],$aid);

$svc->setHold($b1,true);assert($svc->orderShipmentReady($orderId)['ok']===false);$svc->setHold($b1,false);
$db->prepare("UPDATE production_batches SET best_by_date=? WHERE id=?")->execute([gmdate('Y-m-d',time()-86400),$b1]);
assert($svc->orderShipmentReady($orderId)['ok']===false);

$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents) VALUES('FD-HARD2','hard2','preparing','b2@example.com','Buyer','Two','2 Main','Phoenix','AZ','85002','standard','Standard','shipping',1000,1000)");
$order2=(int)$db->lastInsertId();$cfg2=json_encode(['name'=>'One','items'=>[['flavor_id'=>$fids[0],'name'=>'One','quantity'=>1]]],JSON_THROW_ON_ERROR);$s->execute([$order2,'custom',1,1,1000,1000,$cfg2]);
$expired=$svc->createBatch(['batch_code'=>'HARD-OLD','flavor_id'=>$fids[0],'produced_at'=>gmdate('Y-m-d H:i:s',time()-10*86400),'best_by_date'=>gmdate('Y-m-d',time()-2*86400),'quantity_produced'=>5],$aid);
$expiredBlocked=false;try{$svc->assignOrder($order2,[$expired=>1],$aid);}catch(InvalidArgumentException){$expiredBlocked=true;}assert($expiredBlocked);

$futureBlocked=false;try{$svc->createBatch(['batch_code'=>'HARD-FUTURE','flavor_id'=>$fids[0],'produced_at'=>gmdate('Y-m-d H:i:s',time()+86400),'best_by_date'=>gmdate('Y-m-d',time()+8*86400),'quantity_produced'=>5],$aid);}catch(InvalidArgumentException){$futureBlocked=true;}assert($futureBlocked);
$badDate=false;try{$svc->createBatch(['batch_code'=>'HARD-DATE','flavor_id'=>$fids[0],'produced_at'=>$produced,'best_by_date'=>gmdate('Y-m-d',time()-2*86400),'quantity_produced'=>5],$aid);}catch(InvalidArgumentException){$badDate=true;}assert($badDate);

$emptyProduced=$db->quote($produced);$emptyBest=$db->quote($bestBy);$db->exec("INSERT INTO production_batches(batch_code,flavor_id,produced_at,best_by_date,quantity_produced,quantity_remaining,status,created_by) VALUES('HARD-EMPTY',{$fids[0]},{$emptyProduced},{$emptyBest},1,0,'depleted',{$aid})");$depleted=(int)$db->lastInsertId();
$depletedHold=false;try{$svc->setHold($depleted,true);}catch(InvalidArgumentException){$depletedHold=true;}assert($depletedHold);

$db->prepare("UPDATE production_batches SET status='hold',best_by_date=? WHERE id=?")->execute([gmdate('Y-m-d',time()-86400),$expired]);
$expiredRelease=false;try{$svc->setHold($expired,false);}catch(InvalidArgumentException){$expiredRelease=true;}assert($expiredRelease);

$triggerBlocked=false;try{$db->prepare('UPDATE production_batches SET quantity_remaining=quantity_produced+1 WHERE id=?')->execute([$b2]);}catch(PDOException){$triggerBlocked=true;}assert($triggerBlocked);

$db->exec("UPDATE orders SET status='shipped' WHERE id={$orderId}");
$immutable=false;try{$svc->unassignOrder($orderId);}catch(InvalidArgumentException){$immutable=true;}assert($immutable);

echo "Section 67 checks passed\n";
