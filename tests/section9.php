<?php
declare(strict_types=1);$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{Database,OrderService};
$db=Database::connection();$db->exec((string)file_get_contents($root.'/database/006_orders.sql'));
$svc=new OrderService($db);
$cart=['items'=>[['quantity'=>1,'line_total_cents'=>1299,'box'=>['type'=>'custom','size'=>3,'total_cents'=>1299,'items'=>[]]]],'subtotal_cents'=>1299,'discount_cents'=>0,'total_cents'=>1299];
$c=['email'=>'a@b.com','first_name'=>'A','last_name'=>'B','line1'=>'1 Main','line2'=>'','city'=>'Phoenix','region'=>'AZ','postal_code'=>'85001','country'=>'US','phone'=>'','is_gift'=>true,'gift_message'=>'Hi'];
$f=['code'=>'standard','name'=>'Standard Shipping','type'=>'shipping','price_cents'=>899];
$o=$svc->create(null,$cart,$c,$f);$retry=$svc->create(null,$cart,$c,$f);assert($retry['id']===$o['id']);assert($o['status']==='pending_payment');assert((int)$o['total_cents']===2198);assert(count($o['items'])===1);
$svc->attachStripeSession((int)$o['id'],'cs_test_1');$svc->markPaidByStripeSession('cs_test_1',2350,152);$o=$svc->find((int)$o['id']);assert($o['status']==='paid');assert((int)$o['tax_cents']===152);assert((int)$o['total_cents']===2350);
$svc->markPaidByStripeSession('cs_test_1',2350,152);assert($svc->find((int)$o['id'])['status']==='paid');
echo "Section 9 checks passed\n";
