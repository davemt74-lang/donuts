<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{CostAccountingService,Database};

$db=Database::connection();foreach(['001_catalog.sql','006_orders.sql','030_cost_accounting.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));
$svc=new CostAccountingService($db);
$flavor=(int)$db->query('SELECT id FROM flavors ORDER BY id LIMIT 1')->fetchColumn();$pack=(int)$db->query('SELECT id FROM pack_sizes WHERE size=3')->fetchColumn();
$svc->setFlavorCost($flavor,100);$svc->setPackCost($pack,50);
$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,discount_cents,total_cents) VALUES('FD-COST','c1','paid','x@y.com','X','Y','1','Phoenix','AZ','85001','standard','Standard','shipping',1500,100,1400)");
$order=(int)$db->lastInsertId();$cfg=json_encode(['name'=>'3 Pack','items'=>[['flavor_id'=>$flavor,'name'=>'Flavor','quantity'=>3]]],JSON_THROW_ON_ERROR);
$s=$db->prepare('INSERT INTO order_items(order_id,kind,pack_size,quantity,unit_price_cents,line_total_cents,configuration_json) VALUES(?,?,?,?,?,?,?)');$s->execute([$order,'custom',3,1,1500,1500,$cfg]);
$e=$svc->estimateOrder($order);assert($e['product_cost_cents']===300);assert($e['packaging_cost_cents']===50);assert($e['total_cost_cents']===350);assert($e['revenue_basis_cents']===1400);assert($e['gross_margin_cents']===1050);assert($e['margin_percent']===75.0);
$snap=$svc->snapshotOrder($order);assert($snap['gross_margin_cents']===1050);$svc->setFlavorCost($flavor,500);assert($svc->snapshotOrder($order)['gross_margin_cents']===1050);
$sum=$svc->summary();assert($sum['orders']===1);assert($sum['margin_cents']===1050);assert($sum['margin_percent']===75.0);
echo "Section 58 checks passed\n";
