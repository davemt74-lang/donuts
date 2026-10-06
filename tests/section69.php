<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{BatchTraceabilityService,Database,IngredientTraceabilityService,RecipeService};

$db=Database::connection();
foreach(['001_catalog.sql','006_orders.sql','013_admin_accounts.sql','037_batch_traceability.sql','039_ingredient_lot_traceability.sql','040_recipe_bom.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));
$db->exec("INSERT INTO admin_users(email,password_hash,first_name,last_name,role) VALUES('admin@example.com','x','A','D','admin')");$admin=(int)$db->lastInsertId();
$flavor=(int)$db->query("SELECT id FROM flavors ORDER BY id LIMIT 1")->fetchColumn();

$recipes=new RecipeService($db);
$recipeId=$recipes->createVersion($flavor,[
 ['ingredient_name'=>'Chocolate','quantity_per_donut'=>2.5,'quantity_unit'=>'oz'],
 ['ingredient_name'=>'Butter','quantity_per_donut'=>0.5,'quantity_unit'=>'oz'],
],'Initial production formula',$admin,true);
assert($recipes->activeForFlavor($flavor)['id']===$recipeId);
assert($recipes->summary()['flavors_with_recipe']===1);

$trace=new IngredientTraceabilityService($db);
$choc=$trace->createLot(['ingredient_name'=>'Chocolate','supplier_name'=>'Cocoa Co','supplier_lot_code'=>'C1','received_at'=>'2026-10-01 10:00:00','best_by_date'=>'2027-01-01','quantity_received'=>100,'quantity_unit'=>'oz'],$admin);
$butter=$trace->createLot(['ingredient_name'=>'Butter','supplier_name'=>'Dairy Co','supplier_lot_code'=>'B1','received_at'=>'2026-10-01 10:00:00','best_by_date'=>'2027-01-01','quantity_received'=>100,'quantity_unit'=>'oz'],$admin);

$batches=new BatchTraceabilityService($db);
$batch=$batches->createBatch(['batch_code'=>'RECIPE-001','flavor_id'=>$flavor,'produced_at'=>'2026-10-06 08:00:00','best_by_date'=>'2026-10-20','quantity_produced'=>10],$admin);
$req=$recipes->requirementsForBatch($batch);assert(count($req)===2);
$coverage=$recipes->coverage($batch);assert($coverage['controlled']===true);assert($coverage['complete']===false);

$result=$recipes->autoAllocateBatch($batch,$admin);assert($result['allocated']===2);assert($result['coverage']['complete']===true);
$linked=$trace->ingredientsForBatch($batch);assert(count($linked)===2);
$used=[];foreach($linked as $row)$used[$row['ingredient_name']]=(float)$row['quantity_used'];
assert(abs($used['Chocolate']-25.0)<0.000001);assert(abs($used['Butter']-5.0)<0.000001);

$newRecipe=$recipes->createVersion($flavor,[['ingredient_name'=>'Chocolate','quantity_per_donut'=>3,'quantity_unit'=>'oz']],'Version 2',$admin,true);
assert($recipes->activeForFlavor($flavor)['id']===$newRecipe);
assert($recipes->requirementsForBatch($batch)[0]['recipe_version']===1);

$smallBatch=$batches->createBatch(['batch_code'=>'RECIPE-002','flavor_id'=>$flavor,'produced_at'=>'2026-10-06 09:00:00','best_by_date'=>'2026-10-20','quantity_produced'=>30],$admin);
$failed=false;try{$recipes->autoAllocateBatch($smallBatch,$admin);}catch(InvalidArgumentException){$failed=true;}assert($failed);
assert(count($trace->ingredientsForBatch($smallBatch))===0);

echo "Section 69 checks passed\n";
