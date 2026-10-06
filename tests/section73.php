<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';

use FudgeDonuts\{Database,ProductionQaService,ProductionSchedulingService};

$db=Database::connection();
foreach(['001_catalog.sql','006_orders.sql','007_inventory.sql','013_admin_accounts.sql','037_batch_traceability.sql','042_production_scheduling.sql','043_production_qa_waste.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));
$db->exec("INSERT INTO admin_users(email,password_hash,first_name,last_name,role) VALUES('qa@example.com','x','QA','User','admin')");
$adminId=(int)$db->lastInsertId();$flavorId=(int)$db->query('SELECT id FROM flavors ORDER BY id LIMIT 1')->fetchColumn();

$schedule=new ProductionSchedulingService($db);$qa=new ProductionQaService($db);
$workId=$schedule->create(['flavor_id'=>$flavorId,'scheduled_date'=>gmdate('Y-m-d'),'planned_quantity'=>10,'priority'=>'normal'],$adminId);
$schedule->start($workId);

$blocked=false;
try{$schedule->complete($workId,$adminId,8,'BATCH-QA-1',gmdate('Y-m-d',time()+86400));}catch(InvalidArgumentException){$blocked=true;}
assert($blocked);

foreach(ProductionQaService::REQUIRED_CHECKS as $key=>$label){
    $qa->recordCheck($workId,$key,$key==='appearance'?'fail':'pass','',$adminId);
}
$status=$qa->status($workId);assert($status['ok']===false);assert($status['failed']===['appearance']);

$failedBlocked=false;
try{$schedule->complete($workId,$adminId,8,'BATCH-QA-1',gmdate('Y-m-d',time()+86400));}catch(InvalidArgumentException){$failedBlocked=true;}
assert($failedBlocked);

$qa->recordCheck($workId,'appearance','pass','Corrected finish',$adminId);
assert($qa->status($workId)['ok']===true);
$wasteId=$qa->recordWaste($workId,2,'quality','Trimmed rejects',$adminId);assert($wasteId>0);

$batchId=$schedule->complete($workId,$adminId,8,'BATCH-QA-1',gmdate('Y-m-d',time()+86400));assert($batchId>0);
$details=$qa->details($workId);
assert($details['work_order']['status']==='completed');
assert($details['yield']['planned']===10);
assert($details['yield']['actual']===8);
assert($details['yield']['variance']===-2);
assert($details['yield']['variance_percent']===-20.0);
assert($details['yield']['waste']===2);

$summary=$qa->summary();assert($summary['waste_units']===2);assert($summary['yield_warnings']===1);assert($summary['failed_qa_work_orders']===0);

$qa->setYieldWarningPercent(25);assert($qa->yieldWarningPercent()===25);assert($qa->summary()['yield_warnings']===0);

$planned=$schedule->create(['flavor_id'=>$flavorId,'scheduled_date'=>gmdate('Y-m-d'),'planned_quantity'=>1],$adminId);
$badWaste=false;try{$qa->recordWaste($planned,1,'quality','',$adminId);}catch(InvalidArgumentException){$badWaste=true;}assert($badWaste);

echo "Section 73 checks passed\n";
