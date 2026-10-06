<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{Database,IngredientProcurementPlanningService,RecipeService,SupplierPurchasingService};

$db=Database::connection();
foreach(['001_catalog.sql','006_orders.sql','007_inventory.sql','013_admin_accounts.sql','037_batch_traceability.sql','039_ingredient_lot_traceability.sql','040_recipe_bom.sql','041_supplier_purchasing.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));
$db->exec("INSERT INTO admin_users(email,password_hash,first_name,last_name,role) VALUES('admin@example.com','x','A','D','admin')");$admin=(int)$db->lastInsertId();
$flavor=(int)$db->query('SELECT id FROM flavors ORDER BY id LIMIT 1')->fetchColumn();
$db->exec("INSERT OR REPLACE INTO flavor_inventory(flavor_id,track_inventory,stock_on_hand,reserved,low_stock_threshold) VALUES({$flavor},1,0,0,2)");
$cfg=json_encode(['items'=>[['flavor_id'=>$flavor,'name'=>'Test','quantity'=>10]]],JSON_THROW_ON_ERROR);
$o=$db->prepare("INSERT INTO orders(order_number,checkout_fingerprint,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents,created_at) VALUES('FD-PROC','proc1','paid','x@y.com','X','Y','1','Phoenix','AZ','85001','standard','Standard','shipping',1000,1000,CURRENT_TIMESTAMP)");$o->execute();$order=(int)$db->lastInsertId();
$i=$db->prepare("INSERT INTO order_items(order_id,kind,pack_size,quantity,unit_price_cents,line_total_cents,configuration_json) VALUES(?, 'custom',10,1,1000,1000,?)");$i->execute([$order,$cfg]);

$recipes=new RecipeService($db);$recipes->createVersion($flavor,[['ingredient_name'=>'Chocolate','quantity_per_donut'=>2,'quantity_unit'=>'oz']],'Plan',$admin,true);
$db->exec("INSERT INTO ingredient_lots(ingredient_name,supplier_name,supplier_lot_code,received_at,best_by_date,quantity_received,quantity_unit,status,created_by) VALUES('Chocolate','Existing','STOCK','2026-10-01 09:00:00','2027-01-01',5,'oz','active',{$admin})");

$purchasing=new SupplierPurchasingService($db);$supplier=$purchasing->createSupplier(['name'=>'Cocoa Supply','active'=>1],$admin);
$item=$purchasing->createItem($supplier,['ingredient_name'=>'Chocolate','quantity_unit'=>'oz','unit_cost_cents'=>25,'lead_time_days'=>3,'min_order_quantity'=>12]);

$planner=new IngredientProcurementPlanningService($db);$plan=$planner->plan(7,7,0);assert(count($plan['rows'])===1);$row=$plan['rows'][0];
assert(abs((float)$row['required_quantity']-20.0)<0.000001);assert(abs((float)$row['available_quantity']-5.0)<0.000001);assert(abs((float)$row['net_shortage']-15.0)<0.000001);assert(abs((float)$row['suggested_order_quantity']-15.0)<0.000001);assert((int)$row['recommended_supplier_id']===$supplier);

$po=$planner->createRecommendedDraft($supplier,7,7,0,$admin);$draft=$purchasing->purchaseOrder($po);assert($draft['status']==='draft');assert(abs((float)$draft['items'][0]['quantity_ordered']-15.0)<0.000001);
$replanned=$planner->plan(7,7,0);assert(abs((float)$replanned['rows'][0]['net_shortage'])<0.000001);assert($replanned['rows'][0]['risk']==='drafted');

echo "Section 71 checks passed\n";
