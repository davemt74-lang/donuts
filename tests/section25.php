<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{Database,FulfillmentService};
$db=Database::connection();$db->exec((string)file_get_contents($root.'/database/006_orders.sql'));$db->exec((string)file_get_contents($root.'/database/017_fulfillment_details.sql'));
$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents) VALUES('FD-F','ff','paid','x@y.com','X','Y','1','P','AZ','85001','standard','Standard','shipping',1000,1000)");
$id=(int)$db->lastInsertId();$f=new FulfillmentService($db);
$d=$f->save($id,['carrier'=>'UPS','tracking_number'=>'1Z123','tracking_url'=>'https://example.com/track/1Z123']);assert($d['carrier']==='UPS');assert($d['tracking_number']==='1Z123');
$f->markShipped($id);assert($f->details($id)['shipped_at']!==null);$f->markDelivered($id);assert($f->details($id)['delivered_at']!==null);
$bad=false;try{$f->save($id,['tracking_url'=>'not-a-url']);}catch(InvalidArgumentException){$bad=true;}assert($bad);
echo "Section 25 checks passed\n";
