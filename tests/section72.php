<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';

use FudgeDonuts\{Database,ProductionSchedulingService};

$db=Database::connection();
foreach(['001_catalog.sql','006_orders.sql','007_inventory.sql','013_admin_accounts.sql','037_batch_traceability.sql','042_production_scheduling.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));
$db->exec("INSERT INTO admin_users(email,password_hash,first_name,last_name,role) VALUES('ops@example.com','x','Ops','User','admin')");
$adminId=(int)$db->lastInsertId();$flavorId=(int)$db->query('SELECT id FROM flavors ORDER BY id LIMIT 1')->fetchColumn();
$svc=new ProductionSchedulingService($db);

$svc->saveSettings(10,14);
$first=$svc->create(['flavor_id'=>$flavorId,'scheduled_date'=>gmdate('Y-m-d'),'planned_quantity'=>6,'priority'=>'high','assigned_to'=>'Kitchen'],$adminId);
assert($first>0);assert($svc->capacityForDate(gmdate('Y-m-d'))['remaining_units']===4);

$capacityBlocked=false;
try{$svc->create(['flavor_id'=>$flavorId,'scheduled_date'=>gmdate('Y-m-d'),'planned_quantity'=>5],$adminId);}catch(InvalidArgumentException){$capacityBlocked=true;}
assert($capacityBlocked);

$second=$svc->create(['flavor_id'=>$flavorId,'scheduled_date'=>gmdate('Y-m-d'),'planned_quantity'=>4],$adminId);
$svc->start($first);$batchId=$svc->complete($first,$adminId,5,'BATCH-72A',gmdate('Y-m-d',time()+86400));
assert($batchId>0);
$completed=array_values(array_filter($svc->workOrders('completed'),fn($w)=>(int)$w['id']===$first))[0];
assert((int)$completed['actual_quantity']===5);assert($completed['batch_code']==='BATCH-72A');

$svc->start($second);
$duplicate=false;
try{$svc->complete($second,$adminId,4,'BATCH-72A',gmdate('Y-m-d',time()+86400));}catch(InvalidArgumentException){$duplicate=true;}
assert($duplicate);
$stillRunning=array_values(array_filter($svc->workOrders('in_progress'),fn($w)=>(int)$w['id']===$second));
assert(count($stillRunning)===1);

$tomorrow=gmdate('Y-m-d',time()+86400);
$svc->setCapacityOverride($tomorrow,3,'Short shift');
assert($svc->capacityForDate($tomorrow)['max_units']===3);
$future=$svc->create(['flavor_id'=>$flavorId,'scheduled_date'=>$tomorrow,'planned_quantity'=>3],$adminId);
$futureStart=false;try{$svc->start($future);}catch(InvalidArgumentException){$futureStart=true;}assert($futureStart);
$svc->cancel($future);assert(count(array_filter($svc->workOrders('cancelled'),fn($w)=>(int)$w['id']===$future))===1);

$overdue=$svc->create(['flavor_id'=>$flavorId,'scheduled_date'=>gmdate('Y-m-d'),'planned_quantity'=>1],$adminId);
$db->exec("UPDATE production_work_orders SET scheduled_date=date('now','-1 day') WHERE id=".$overdue);
assert($svc->summary()['overdue']===1);

echo "Section 72 checks passed\n";
