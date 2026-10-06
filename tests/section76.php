<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';

use FudgeDonuts\{BatchTraceabilityService,Database,FulfillmentWaveService};

$db=Database::connection();
foreach(['001_catalog.sql','006_orders.sql','013_admin_accounts.sql','037_batch_traceability.sql','046_fulfillment_waves.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));
$db->exec("INSERT INTO admin_users(email,password_hash,first_name,last_name,role) VALUES('wave@example.com','x','Wave','User','admin')");
$adminId=(int)$db->lastInsertId();$flavorId=(int)$db->query('SELECT id FROM flavors ORDER BY id LIMIT 1')->fetchColumn();
$db->exec("INSERT INTO production_batches(batch_code,flavor_id,produced_at,best_by_date,quantity_produced,quantity_remaining,status,created_by) VALUES('WAVE-BATCH',{$flavorId},datetime('now','-1 day'),date('now','+5 day'),20,20,'active',{$adminId})");
$batchId=(int)$db->lastInsertId();

$insertOrder=$db->prepare("INSERT INTO orders(order_number,checkout_fingerprint,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
$item=$db->prepare("INSERT INTO order_items(order_id,kind,pack_size,quantity,unit_price_cents,line_total_cents,configuration_json) VALUES(?,?,?,?,?,?,?)");
$orderIds=[];
foreach([1,2] as $n){
    $insertOrder->execute(["FD-WAVE{$n}","wave{$n}",'preparing',"x{$n}@y.com",'Pack',"{$n}",'1 Main','Phoenix','AZ','85001','standard','Standard','shipping',1000,1000]);
    $oid=(int)$db->lastInsertId();$orderIds[]=$oid;
    $cfg=json_encode(['items'=>[['flavor_id'=>$flavorId,'name'=>'Flavor','quantity'=>2]]],JSON_THROW_ON_ERROR);
    $item->execute([$oid,'custom',2,1,1000,1000,$cfg]);
    (new BatchTraceabilityService($db))->assignOrder($oid,[$batchId=>2],$adminId);
}
$svc=new FulfillmentWaveService($db);
$eligible=$svc->eligible('shipping');assert(count($eligible)===2);assert($eligible[0]['traceability_ok']===true);

$waveId=$svc->create($orderIds,'shipping','Packing Team','Morning wave',$adminId);assert($waveId>0);
$duplicate=false;try{$svc->create([$orderIds[0]],'shipping','','',$adminId);}catch(InvalidArgumentException){$duplicate=true;}assert($duplicate);
assert(count($svc->wave($waveId)['orders'])===2);

$svc->start($waveId);
$svc->setPacked($waveId,$orderIds[0],true,$adminId);
$notAllPacked=false;try{$svc->complete($waveId);}catch(InvalidArgumentException){$notAllPacked=true;}assert($notAllPacked);
assert((string)$db->query("SELECT status FROM orders WHERE id={$orderIds[0]}")->fetchColumn()==='preparing');

$svc->setPacked($waveId,$orderIds[1],true,$adminId);
$readyIds=$svc->complete($waveId);sort($readyIds);$expected=$orderIds;sort($expected);assert($readyIds===$expected);
assert((int)$db->query("SELECT COUNT(*) FROM orders WHERE status='ready'")->fetchColumn()===2);
assert((int)$db->query("SELECT COUNT(*) FROM fulfillment_wave_orders WHERE wave_id={$waveId} AND active=1")->fetchColumn()===0);
$history=$svc->wave($waveId);assert($history['status']==='completed');assert(count($history['orders'])===2);assert($history['orders'][0]['packed_at']!==null);
$pick=$svc->pickSummary($waveId);assert(count($pick)===1);assert((int)$pick[0]['quantity']===4);

$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents) VALUES('FD-CANCEL','cancel-wave','preparing','c@y.com','C','One','1 Main','Phoenix','AZ','85001','standard','Standard','shipping',1000,1000)");
$cancelId=(int)$db->lastInsertId();$cfg=json_encode(['items'=>[['flavor_id'=>$flavorId,'name'=>'Flavor','quantity'=>1]]],JSON_THROW_ON_ERROR);$item->execute([$cancelId,'custom',1,1,1000,1000,$cfg]);(new BatchTraceabilityService($db))->assignOrder($cancelId,[$batchId=>1],$adminId);
$wave2=$svc->create([$cancelId],'shipping','','',$adminId);$svc->cancel($wave2);
assert((string)$db->query("SELECT status FROM orders WHERE id={$cancelId}")->fetchColumn()==='preparing');
assert((int)$db->query("SELECT active FROM fulfillment_wave_orders WHERE wave_id={$wave2} AND order_id={$cancelId}")->fetchColumn()===0);
$wave3=$svc->create([$cancelId],'shipping','','',$adminId);assert($wave3!==$wave2);

echo "Section 76 checks passed\n";
