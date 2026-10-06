<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{AdminService,Database,FulfillmentOperationsService,PackagingInventoryService};

$db=Database::connection();
foreach(['001_catalog.sql','003_accounts.sql','006_orders.sql','013_admin_accounts.sql','049_packaging_inventory.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));
$db->exec("INSERT INTO admin_users(email,password_hash,first_name,last_name,role) VALUES('admin@example.com','x','A','D','admin')");$admin=(int)$db->lastInsertId();
$svc=new PackagingInventoryService($db);$materials=$svc->materials();assert(count($materials)>=5);
foreach($materials as $m)$svc->adjust((int)$m['id'],100,'Initial count',$admin);

$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents) VALUES('FD-PKG','pkg1','paid','x@y.com','X','Y','1','P','AZ','85001','standard','Standard','shipping',1000,1000)");$order=(int)$db->lastInsertId();
$cfg=json_encode(['items'=>[]],JSON_THROW_ON_ERROR);$s=$db->prepare("INSERT INTO order_items(order_id,kind,pack_size,quantity,unit_price_cents,line_total_cents,configuration_json) VALUES(?,?,?,?,?,?,?)");$s->execute([$order,'custom',6,2,500,1000,$cfg]);
$needs=$svc->orderNeeds($order);$map=[];foreach($needs as $n)$map[$n['sku']]=(int)$n['quantity'];assert($map['BOX-6']===2);assert($map['WRAPPER']===12);assert($map['BRAND-LABEL']===2);

$svc->reserveOrder($order,$admin);$after=$svc->materials();$stock=[];foreach($after as $m)$stock[$m['sku']]=(int)$m['stock_on_hand'];assert($stock['BOX-6']===98);assert($stock['WRAPPER']===88);
$svc->reserveOrder($order,$admin);$again=$svc->materials();$stockAgain=[];foreach($again as $m)$stockAgain[$m['sku']]=(int)$m['stock_on_hand'];assert($stockAgain['BOX-6']===98);
$svc->releaseOrder($order,$admin);$released=[];foreach($svc->materials() as $m)$released[$m['sku']]=(int)$m['stock_on_hand'];assert($released['BOX-6']===100);

$svc->reserveOrder($order,$admin);$svc->consumeOrder($order,$admin);$svc->releaseOrder($order,$admin);$consumed=[];foreach($svc->materials() as $m)$consumed[$m['sku']]=(int)$m['stock_on_hand'];assert($consumed['BOX-6']===98);

$box6=array_values(array_filter($svc->materials(),fn($m)=>$m['sku']==='BOX-6'))[0];$svc->adjust((int)$box6['id'],0,'Depleted for test',$admin);
$plan=$svc->plan();$row=array_values(array_filter($plan['rows'],fn($m)=>$m['sku']==='BOX-6'))[0];assert($row['shortage_units']>0);assert($row['suggested_purchase']>=75);

// Individual fulfillment must fail atomically when required packaging is unavailable.
$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents) VALUES('FD-PKG2','pkg2','paid','x@y.com','X','Y','1','P','AZ','85001','standard','Standard','shipping',1000,1000)");
$order2=(int)$db->lastInsertId();$s->execute([$order2,'custom',6,1,1000,1000,$cfg]);
$adminSvc=new AdminService($db);$blocked=false;try{$adminSvc->transitionOrder($order2,'preparing');}catch(RuntimeException){$blocked=true;}assert($blocked);assert((string)$db->query("SELECT status FROM orders WHERE id={$order2}")->fetchColumn()==='paid');
$svc->receive((int)$box6['id'],10,'Restock',$admin);$adminSvc->transitionOrder($order2,'preparing');assert((string)$db->query("SELECT status FROM orders WHERE id={$order2}")->fetchColumn()==='preparing');
$adminSvc->transitionOrder($order2,'ready');assert((string)$db->query("SELECT status FROM orders WHERE id={$order2}")->fetchColumn()==='ready');
assert((int)$db->query("SELECT COUNT(*) FROM order_packaging_allocations WHERE order_id={$order2} AND status='consumed'")->fetchColumn()>0);

// Batch fulfillment must roll back all selected orders when one order cannot reserve packaging.
$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents) VALUES('FD-PKG3','pkg3','paid','x@y.com','X','Y','1','P','AZ','85001','standard','Standard','shipping',1000,1000)");
$order3=(int)$db->lastInsertId();$s->execute([$order3,'custom',12,1,1000,1000,$cfg]);
$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents) VALUES('FD-PKG4','pkg4','paid','x@y.com','X','Y','1','P','AZ','85001','standard','Standard','shipping',1000,1000)");
$order4=(int)$db->lastInsertId();$s->execute([$order4,'custom',12,1,1000,1000,$cfg]);
$box12=array_values(array_filter($svc->materials(),fn($m)=>$m['sku']==='BOX-12'))[0];$svc->adjust((int)$box12['id'],1,'Only one box available',$admin);
$batchOps=new FulfillmentOperationsService($db);$batchBlocked=false;try{$batchOps->batchTransition([$order3,$order4],'preparing');}catch(RuntimeException){$batchBlocked=true;}assert($batchBlocked);
assert((int)$db->query("SELECT COUNT(*) FROM orders WHERE id IN ({$order3},{$order4}) AND status='paid'")->fetchColumn()===2);
assert((int)$db->query("SELECT stock_on_hand FROM packaging_materials WHERE id=".(int)$box12['id'])->fetchColumn()===1);

echo "Section 79 checks passed\n";
