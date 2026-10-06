<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{Database,SupplierPurchasingService};

$db=Database::connection();
foreach(['001_catalog.sql','006_orders.sql','013_admin_accounts.sql','037_batch_traceability.sql','039_ingredient_lot_traceability.sql','041_supplier_purchasing.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));
$db->exec("INSERT INTO admin_users(email,password_hash,first_name,last_name,role) VALUES('admin@example.com','x','A','D','admin')");$admin=(int)$db->lastInsertId();

$svc=new SupplierPurchasingService($db);
$supplier=$svc->createSupplier(['name'=>'Cocoa Supply','contact_name'=>'Casey','email'=>'orders@cocoa.example','active'=>1],$admin);
$item=$svc->createItem($supplier,['ingredient_name'=>'Chocolate','supplier_sku'=>'CHO-1','quantity_unit'=>'OZ','unit_cost_cents'=>35,'lead_time_days'=>3,'min_order_quantity'=>10]);
$po=$svc->createPurchaseOrder($supplier,[['supplier_item_id'=>$item,'quantity_ordered'=>50]],'2026-10-10','Test PO',$admin);
assert($svc->purchaseOrder($po)['status']==='draft');$svc->markOrdered($po);assert($svc->purchaseOrder($po)['status']==='ordered');

$lot1=$svc->receive((int)$svc->purchaseOrder($po)['items'][0]['id'],['quantity_received'=>20,'supplier_lot_code'=>'LOT-A','received_at'=>'2026-10-06 09:00:00','best_by_date'=>'2027-01-01'],$admin);
assert($lot1>0);$p=$svc->purchaseOrder($po);assert($p['status']==='partially_received');assert(abs((float)$p['items'][0]['remaining_quantity']-30.0)<0.000001);
$lot=$db->query('SELECT * FROM ingredient_lots WHERE id='.$lot1)->fetch();assert($lot['supplier_name']==='Cocoa Supply');assert($lot['quantity_unit']==='oz');

$lot2=$svc->receive((int)$p['items'][0]['id'],['quantity_received'=>30,'supplier_lot_code'=>'LOT-B','received_at'=>'2026-10-06 10:00:00','best_by_date'=>'2027-01-01'],$admin);
assert($lot2>0);assert($svc->purchaseOrder($po)['status']==='received');

$over=false;try{$svc->receive((int)$p['items'][0]['id'],['quantity_received'=>1,'supplier_lot_code'=>'LOT-C','received_at'=>'2026-10-06 11:00:00'],$admin);}catch(InvalidArgumentException){$over=true;}assert($over);
assert($svc->summary()['active_suppliers']===1);

echo "Section 70 checks passed\n";
