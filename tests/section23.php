<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{Database,InventoryService,OrderService,PaymentRepository};
$db=Database::connection();
foreach(['001_catalog.sql','004_payments.sql','006_orders.sql','007_inventory.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));

$fid=(int)$db->query("SELECT id FROM flavors WHERE slug='smores'")->fetchColumn();
$db->exec("UPDATE flavor_inventory SET track_inventory=1,stock_on_hand=10,reserved=0 WHERE flavor_id={$fid}");
$cart=['items'=>[['quantity'=>1,'line_total_cents'=>1299,'box'=>['type'=>'custom','size'=>3,'total_cents'=>1299,'items'=>[['flavor_id'=>$fid,'quantity'=>3]]]]],'subtotal_cents'=>1299,'discount_cents'=>0,'total_cents'=>1299];
$c=['email'=>'x@example.com','first_name'=>'X','last_name'=>'Y','line1'=>'1 Main','line2'=>'','city'=>'Phoenix','region'=>'AZ','postal_code'=>'85001','country'=>'US','phone'=>'','is_gift'=>false,'gift_message'=>''];
$f=['code'=>'standard','name'=>'Standard','type'=>'shipping','price_cents'=>0];
$orders=new OrderService($db);$order=$orders->create(null,$cart,$c,$f);
$inv=new InventoryService($db);$inv->reserveOrder((int)$order['id'],$cart);assert($inv->availability($fid)===7);
$orders->attachStripeSession((int)$order['id'],'cs_fail_1');$orders->markPaymentFailedByStripeSession('cs_fail_1','expired');$inv->releaseOrder((int)$order['id']);assert($inv->availability($fid)===10);
$retry=$orders->preparePaymentAttempt((int)$order['id']);assert($retry['status']==='pending_payment');assert($retry['stripe_checkout_session_id']===null);
$inv->reserveOrder((int)$order['id'],$cart);assert($inv->availability($fid)===7);
$orders->attachStripeSession((int)$order['id'],'cs_ok_2');$orders->markPaidByStripeSession('cs_ok_2',1299,0);$inv->commitOrder((int)$order['id']);assert($inv->availability($fid)===7);assert($orders->findByStripeSession('cs_ok_2')['status']==='paid');

$payments=new PaymentRepository($db);$attempt=$payments->createSession(null,1299,['order_id'=>$order['id']]);$payments->attachProviderSession((int)$attempt['id'],'cs_pay_1');$payments->markCompletedByProviderSession('cs_pay_1');
$status=$db->query("SELECT status FROM payment_sessions WHERE id=".(int)$attempt['id'])->fetchColumn();assert($status==='completed');

$paidBlocked=false;try{$orders->preparePaymentAttempt((int)$order['id']);}catch(RuntimeException){$paidBlocked=true;}assert($paidBlocked);
echo "Section 23 checks passed\n";
