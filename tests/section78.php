<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{BatchLabelService,BatchTraceabilityService,Database,FoodComplianceService,ProductionQaService,ProductionSchedulingService};

$db=Database::connection();
foreach(['001_catalog.sql','003_accounts.sql','006_orders.sql','013_admin_accounts.sql','036_food_compliance.sql','037_batch_traceability.sql','042_production_scheduling.sql','043_production_qa_waste.sql','048_batch_compliance_labels.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));
$db->exec("INSERT INTO flavors(slug,name,description,active,sort_order) VALUES('label','Label Flavor','Label',1,1)");$flavor=(int)$db->lastInsertId();
$db->exec("INSERT INTO admin_users(email,password_hash,first_name,last_name,role) VALUES('admin@example.com','x','A','D','admin')");$admin=(int)$db->lastInsertId();
$food=new FoodComplianceService($db);
$food->save($flavor,['ingredient_statement'=>'Chocolate, sugar','allergen_statement'=>'Contains milk','shared_kitchen_notice'=>'Shared kitchen','storage_instructions'=>'Keep cool','shelf_life_days'=>10,'net_weight_oz'=>2.5,'label_version'=>'v1','published'=>1],$admin);

$label=new BatchLabelService($db);$prepared=$label->prepare($flavor,'2026-10-01 10:00:00',null);assert($prepared['best_by_date']==='2026-10-11');
$blocked=false;try{$label->prepare($flavor,'2026-10-01 10:00:00','2026-10-15');}catch(InvalidArgumentException){$blocked=true;}assert($blocked);

$trace=new BatchTraceabilityService($db);$batch=$trace->createBatch(['batch_code'=>'LBL-001','flavor_id'=>$flavor,'produced_at'=>'2026-10-01 10:00:00','best_by_date'=>'','quantity_produced'=>12,'notes'=>''],$admin);
$row=$label->forBatch($batch);assert($row['best_by_date']==='2026-10-11');assert($row['label_version']==='v1');assert($row['ingredient_statement']==='Chocolate, sugar');

$food->save($flavor,['ingredient_statement'=>'Chocolate, sugar, vanilla','allergen_statement'=>'Contains milk','shared_kitchen_notice'=>'Shared kitchen','storage_instructions'=>'Keep cool','shelf_life_days'=>7,'net_weight_oz'=>2.5,'label_version'=>'v2','published'=>1],$admin);
$row2=$label->forBatch($batch);assert($row2['label_version']==='v1');assert($row2['ingredient_statement']==='Chocolate, sugar');assert($row2['best_by_date']==='2026-10-11');

$schedule=new ProductionSchedulingService($db);
$work=$schedule->create(['flavor_id'=>$flavor,'scheduled_date'=>gmdate('Y-m-d'),'planned_quantity'=>8,'priority'=>'normal','assigned_to'=>'','notes'=>''],$admin);
$schedule->start($work);$qa=new ProductionQaService($db);
foreach(ProductionQaService::REQUIRED_CHECKS as $key=>$labelName)$qa->recordCheck($work,$key,'pass','',$admin);
$batch2=$schedule->complete($work,$admin,8,'LBL-002',null);
$row3=$label->forBatch($batch2);assert($row3['label_version']==='v2');assert($row3['shelf_life_days']===7);assert($row3['best_by_date']===(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('+7 days')->format('Y-m-d'));
echo "Section 78 checks passed\n";
