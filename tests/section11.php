<?php
declare(strict_types=1);$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{Database,InventoryService,OrderService};
$db=Database::connection();foreach(['001_catalog.sql','006_orders.sql','007_inventory.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));
$flavors=$db->query('SELECT id,slug FROM flavors')->fetchAll();$ids=array_column($flavors,'id','slug');
$svc=new InventoryService($db);$svc->setInventory((int)$ids['smores'],true,10,2);
$cart=['items'=>[['quantity'=>2,'line_total_cents'=>2598,'box'=>['type'=>'custom','size'=>3,'total_cents'=>1299,'items'=>[['flavor_id'=>(int)$ids['smores'],'quantity'=>3]]]]],'subtotal_cents'=>2598,'discount_cents'=>0,'total_cents'=>2598];
$svc->validateCart($cart);assert($svc->availability((int)$ids['smores'])===10);
$c=['email'=>'a@b.com','first_name'=>'A','last_name'=>'B','line1'=>'1','line2'=>'','city'=>'Phoenix','region'=>'AZ','postal_code'=>'85001','country'=>'US','phone'=>'','is_gift'=>false,'gift_message'=>''];$f=['code'=>'standard','name'=>'Standard','type'=>'shipping','price_cents'=>0];
$o=(new OrderService($db))->create(null,$cart,$c,$f);$svc->reserveOrder((int)$o['id'],$cart);assert($svc->availability((int)$ids['smores'])===4);$svc->commitOrder((int)$o['id']);assert($svc->availability((int)$ids['smores'])===4);
echo "Section 11 checks passed\n";
