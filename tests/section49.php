<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{Database,FulfillmentOperationsService};

$db=Database::connection();foreach(['006_orders.sql','011_gifts.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));
foreach([1,2] as $n)$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents) VALUES('FD-B{$n}','b{$n}','paid','x@y.com','X','Y','1 Main','Phoenix','AZ','85001','standard','Standard','shipping',1000,1000)");
$svc=new FulfillmentOperationsService($db);$ids=array_map('intval',$db->query('SELECT id FROM orders ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
$db->exec("INSERT INTO order_items(order_id,kind,pack_size,quantity,unit_price_cents,line_total_cents,configuration_json) VALUES({$ids[0]},'custom',3,1,1000,1000,'{"name":"Custom 3 Pack","items":[{"name":"S''mores","quantity":3}]}')");
assert($svc->batchTransition($ids,'preparing')===2);assert($db->query("SELECT COUNT(*) FROM orders WHERE status='preparing'")->fetchColumn()==2);
assert($svc->batchTransition($ids,'ready')===2);assert(count($svc->shippingRows('ready'))===2);
$slip=$svc->packingSlip($ids[0]);assert($slip['order_number']==='FD-B1');assert($slip['items'][0]['configuration']['name']==='Custom 3 Pack');

$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents) VALUES('FD-PICK','pick1','ready','p@y.com','Pickup','One','1 Main','Phoenix','AZ','85001','pickup','Local Pickup','pickup',900,900)");
assert(count($svc->pickupRows('ready'))===1);

$bad=false;try{$svc->batchTransition($ids,'shipped');}catch(InvalidArgumentException){$bad=true;}assert($bad);
$db->exec("UPDATE orders SET status='paid' WHERE id={$ids[0]}");
$atomic=false;try{$svc->batchTransition($ids,'preparing');}catch(InvalidArgumentException){$atomic=true;}assert($atomic);assert((string)$db->query("SELECT status FROM orders WHERE id={$ids[1]}")->fetchColumn()==='ready');

assert(FulfillmentOperationsService::csvCell('=2+2')==="'=2+2");
assert(FulfillmentOperationsService::csvCell('@cmd')==="'@cmd");
assert(FulfillmentOperationsService::csvCell("hello\nworld")==='hello world');
echo "Section 49 checks passed\n";
