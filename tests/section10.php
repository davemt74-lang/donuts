<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{AdminService,Database,OrderService};
$db=Database::connection();foreach(['002_discounts.sql','005_shipping.sql','006_orders.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));
$orders=new OrderService($db);$cart=['items'=>[['quantity'=>1,'line_total_cents'=>1299,'box'=>['type'=>'custom','size'=>3,'total_cents'=>1299,'items'=>[]]]],'subtotal_cents'=>1299,'discount_cents'=>0,'total_cents'=>1299];$c=['email'=>'a@b.com','first_name'=>'A','last_name'=>'B','line1'=>'1 Main','line2'=>'','city'=>'Phoenix','region'=>'AZ','postal_code'=>'85001','country'=>'US','phone'=>'','is_gift'=>false,'gift_message'=>''];$f=['code'=>'standard','name'=>'Standard','type'=>'shipping','price_cents'=>899];$o=$orders->create(null,$cart,$c,$f);
$admin=new AdminService($db);$admin->transitionOrder((int)$o['id'],'cancelled','Customer requested cancellation');assert($admin->order((int)$o['id'])['status']==='cancelled');
$admin->setPickupZip('85001',true);assert($admin->pickupZips()[0]['postal_code']==='85001');$admin->setPickupZip('85001',false);assert((int)$admin->pickupZips()[0]['active']===0);
echo "Section 10 checks passed\n";
