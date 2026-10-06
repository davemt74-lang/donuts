<?php
declare(strict_types=1);$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{Database,ReportingService};
$db=Database::connection();$db->exec((string)file_get_contents($root.'/database/006_orders.sql'));
$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents) VALUES
('FD-1','r1','paid','a@b.com','A','B','1','P','AZ','85001','standard','Standard','shipping',1000,1200),
('FD-2','r2','cancelled','c@d.com','C','D','2','P','AZ','85001','pickup','Pickup','pickup',2000,2000)");
$id=(int)$db->query("SELECT id FROM orders WHERE order_number='FD-1'")->fetchColumn();
$cfg=json_encode(['items'=>[['name'=>'S\'mores','quantity'=>3]]],JSON_THROW_ON_ERROR);
$s=$db->prepare("INSERT INTO order_items(order_id,kind,pack_size,quantity,unit_price_cents,line_total_cents,configuration_json) VALUES(?,?,?,?,?,?,?)");$s->execute([$id,'custom',3,2,500,1000,$cfg]);
$r=new ReportingService($db);$o=$r->overview();assert($o['orders']===1);assert($o['revenue_cents']===1200);assert($r->packPerformance()[0]['boxes']==2);assert($r->flavorPerformance()[0]['units']===6);
echo "Section 16 checks passed\n";
