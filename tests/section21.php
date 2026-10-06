<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{AdminDashboardService,Database};
$db=Database::connection();foreach(['001_catalog.sql','002_discounts.sql','006_orders.sql','007_inventory.sql','009_promotions.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));
$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,discount_cents,total_cents,created_at) VALUES
('FD-D1','d1','paid','a@b.com','A','B','1','Phoenix','AZ','85001','standard','Standard','shipping',2500,100,2500,CURRENT_TIMESTAMP),
('FD-D2','d2','preparing','c@d.com','C','D','2','Phoenix','AZ','85001','pickup','Pickup','pickup',4000,0,4000,CURRENT_TIMESTAMP),
('FD-D3','d3','cancelled','e@f.com','E','F','3','Phoenix','AZ','85001','standard','Standard','shipping',3000,0,3000,CURRENT_TIMESTAMP)");
$paid=(int)$db->query("SELECT id FROM orders WHERE order_number='FD-D1'")->fetchColumn();$prep=(int)$db->query("SELECT id FROM orders WHERE order_number='FD-D2'")->fetchColumn();
$cfg=json_encode(['items'=>[['name'=>"S'mores",'quantity'=>3]]],JSON_THROW_ON_ERROR);
$s=$db->prepare("INSERT INTO order_items(order_id,kind,pack_size,quantity,unit_price_cents,line_total_cents,configuration_json) VALUES(?,?,?,?,?,?,?)");$s->execute([$paid,'custom',3,1,2500,2500,$cfg]);$s->execute([$prep,'custom',6,1,4000,4000,$cfg]);
$fid=(int)$db->query("SELECT id FROM flavors WHERE slug='smores'")->fetchColumn();$db->exec("UPDATE flavor_inventory SET track_inventory=1,stock_on_hand=4,reserved=0,low_stock_threshold=5 WHERE flavor_id={$fid}");
$svc=new AdminDashboardService($db);$d=$svc->snapshot();
assert($d['today']['orders']===2);assert($d['today']['revenue_cents']===6500);assert($d['fulfillment']['paid']===1);assert($d['fulfillment']['preparing']===1);assert(count($d['recent_orders'])===3);assert(count($d['low_stock'])===1);assert($d['top_flavors'][0]['units']===6);assert(count($d['daily_sales'])===30);assert($svc->percentChange(150,100)===50.0);assert($svc->percentChange(100,0)===null);
echo "Section 21 checks passed\n";
