<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{Database,ShipmentService};

$db=Database::connection();foreach(['006_orders.sql','017_fulfillment_details.sql','036_shipments.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));
$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents) VALUES('FD-SHIP','ship1','preparing','x@y.com','X','Y','1','Phoenix','AZ','85001','standard','Standard','shipping',2000,2000)");
$orderId=(int)$db->lastInsertId();
$cfg=json_encode(['name'=>'6 Pack'],JSON_THROW_ON_ERROR);$i=$db->prepare("INSERT INTO order_items(order_id,kind,pack_size,quantity,unit_price_cents,line_total_cents,configuration_json) VALUES(?,?,?,?,?,?,?)");$i->execute([$orderId,'custom',6,2,1000,2000,$cfg]);$itemId=(int)$db->lastInsertId();
$svc=new ShipmentService($db);
$s1=$svc->create($orderId,['carrier'=>'UPS','tracking_number'=>'1Z-ONE','tracking_url'=>'https://example.com/one'],[$itemId=>1]);assert($s1['status']==='pending');assert($svc->remainingItems($orderId)[0]['remaining']==1);
$s2=$svc->create($orderId,['carrier'=>'UPS','tracking_number'=>'1Z-TWO','tracking_url'=>'https://example.com/two'],[$itemId=>1]);assert($svc->remainingItems($orderId)[0]['remaining']==0);
$svc->markShipped((int)$s1['id']);assert($db->query("SELECT status FROM orders WHERE id={$orderId}")->fetchColumn()==='preparing');
$svc->markShipped((int)$s2['id']);assert($db->query("SELECT status FROM orders WHERE id={$orderId}")->fetchColumn()==='shipped');assert($svc->allShipped($orderId));assert($db->query("SELECT shipped_at FROM order_fulfillment_details WHERE order_id={$orderId}")->fetchColumn()!==false);
$svc->markDelivered((int)$s1['id']);assert($db->query("SELECT status FROM orders WHERE id={$orderId}")->fetchColumn()==='shipped');$svc->markDelivered((int)$s2['id']);assert($db->query("SELECT status FROM orders WHERE id={$orderId}")->fetchColumn()==='delivered');assert($db->query("SELECT delivered_at FROM order_fulfillment_details WHERE order_id={$orderId}")->fetchColumn()!==false);
$over=false;try{$svc->create($orderId,['carrier'=>'UPS'],[$itemId=>1]);}catch(InvalidArgumentException){$over=true;}assert($over);
assert(count($svc->forOrder($orderId))===2);
echo "Section 65 checks passed\n";
