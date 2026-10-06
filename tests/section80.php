<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{Database,PackagingInventoryService,PackagingPurchasingService};

$db=Database::connection();
foreach(['001_catalog.sql','003_accounts.sql','006_orders.sql','013_admin_accounts.sql','049_packaging_inventory.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));
$db->exec("CREATE TABLE suppliers(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL,email TEXT NOT NULL DEFAULT '',active INTEGER NOT NULL DEFAULT 1)");
$db->exec((string)file_get_contents($root.'/database/050_packaging_purchasing.sql'));
$db->exec("INSERT INTO suppliers(name,email,active) VALUES('Box Co','box@example.com',1)");$supplier=(int)$db->lastInsertId();
$db->exec("INSERT INTO admin_users(email,password_hash,first_name,last_name,role) VALUES('admin@example.com','x','A','D','admin')");$admin=(int)$db->lastInsertId();
$inv=new PackagingInventoryService($db);$box=array_values(array_filter($inv->materials(),fn($m)=>$m['sku']==='BOX-6'))[0];
$svc=new PackagingPurchasingService($db);$item=$svc->saveSupplierItem(['supplier_id'=>$supplier,'material_id'=>$box['id'],'supplier_sku'=>'B6','unit_cost_cents'=>125,'lead_time_days'=>3,'min_order_quantity'=>50,'active'=>1]);
assert($item>0);
$inv->adjust((int)$box['id'],0,'Start empty',$admin);$rec=$svc->recommendations();$row=array_values(array_filter($rec['rows'],fn($r)=>$r['sku']==='BOX-6'))[0];assert($row['supplier_item']['supplier_name']==='Box Co');assert($row['recommended_order_quantity']>=50);
$po=$svc->createPurchaseOrder($supplier,[['supplier_item_id'=>$item,'quantity_ordered'=>75]],gmdate('Y-m-d',time()+86400),'Restock boxes',$admin);$svc->markOrdered($po);
$afterOrder=$svc->recommendations();assert(count(array_filter($afterOrder['rows'],fn($r)=>$r['sku']==='BOX-6'))===0);
$detail=$svc->purchaseOrder($po);assert($detail['status']==='ordered');$line=$detail['items'][0];
$svc->receive((int)$line['id'],25,gmdate('Y-m-d H:i:s'),'Partial receipt',$admin);$detail=$svc->purchaseOrder($po);assert($detail['status']==='partially_received');assert((int)$detail['items'][0]['quantity_received']===25);
$stock=array_values(array_filter($inv->materials(),fn($m)=>$m['sku']==='BOX-6'))[0];assert((int)$stock['stock_on_hand']===25);
$svc->receive((int)$line['id'],50,gmdate('Y-m-d H:i:s'),'Final receipt',$admin);assert($svc->purchaseOrder($po)['status']==='received');
$stock=array_values(array_filter($inv->materials(),fn($m)=>$m['sku']==='BOX-6'))[0];assert((int)$stock['stock_on_hand']===75);
echo "Section 80 checks passed\n";
