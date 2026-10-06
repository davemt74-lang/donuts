<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';

use FudgeDonuts\{Database,InventoryService,OrderService};

$db=Database::connection();
foreach(['001_catalog.sql','006_orders.sql','007_inventory.sql','021_inventory_reservation_leases.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));

$fid=(int)$db->query("SELECT id FROM flavors WHERE slug='smores'")->fetchColumn();
$db->exec("UPDATE flavor_inventory SET track_inventory=1,stock_on_hand=12,reserved=0 WHERE flavor_id={$fid}");
$cart=['items'=>[['quantity'=>1,'line_total_cents'=>1200,'box'=>['type'=>'custom','size'=>3,'total_cents'=>1200,'items'=>[['flavor_id'=>$fid,'quantity'=>3]]]]],'subtotal_cents'=>1200,'discount_cents'=>0,'discounts'=>[],'total_cents'=>1200];
$checkout=['email'=>'hold@example.com','first_name'=>'Hold','last_name'=>'Buyer','line1'=>'1 Main','line2'=>'','city'=>'Phoenix','region'=>'AZ','postal_code'=>'85001','country'=>'US','phone'=>'','is_gift'=>false,'gift_message'=>''];
$fulfillment=['code'=>'standard','name'=>'Standard','type'=>'shipping','price_cents'=>0];

$orders=new OrderService($db);
$order=$orders->create(null,$cart,$checkout,$fulfillment,'reservation_test_A_1234567890abcdef1234567890');
$inventory=new InventoryService($db);
$inventory->reserveOrder((int)$order['id'],$cart,'2000-01-01 00:00:00');
assert($inventory->availability($fid)===9);
assert(count($inventory->expiredLeases())===1);
assert($inventory->reservationStats()['expired']===1);

$inventory->releaseOrder((int)$order['id']);
assert($inventory->availability($fid)===12);
assert($inventory->lease((int)$order['id'])['status']==='released');
assert(count($inventory->expiredLeases())===0);

$inventory->reserveOrder((int)$order['id'],$cart,'2099-01-01 00:00:00');
$inventory->holdForReview((int)$order['id']);
assert($inventory->lease((int)$order['id'])['status']==='review_hold');
assert($inventory->reservationStats()['review_hold']===1);

$order2=$orders->create(null,$cart,$checkout,$fulfillment,'reservation_test_B_1234567890abcdef1234567890');
$inventory->reserveOrder((int)$order2['id'],$cart,'2099-01-01 00:00:00');
$inventory->commitOrder((int)$order2['id']);
assert($inventory->lease((int)$order2['id'])['status']==='committed');
assert($inventory->availability($fid)===6);

echo "Section 35 checks passed\n";
