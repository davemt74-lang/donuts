<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{AdminService,Database,RefundService};
$db=Database::connection();foreach(['003_accounts.sql','006_orders.sql','013_admin_accounts.sql','016_order_refunds.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));
$db->exec("INSERT INTO users(email,password_hash) VALUES('u@example.com','x')");$uid=(int)$db->lastInsertId();
$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,user_id,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents) VALUES('FD-R','rf',{$uid},'paid','u@example.com','U','S','1','P','AZ','85001','standard','Standard','shipping',5000,5000)");
$orderId=(int)$db->lastInsertId();$db->exec("INSERT INTO order_payment_details(order_id,stripe_payment_intent_id) VALUES({$orderId},'pi_1')");
$db->exec("INSERT INTO admin_users(email,password_hash,first_name,last_name,role) VALUES('a@example.com','x','A','D','super_admin')");
$r=new RefundService($db);assert($r->refundableCents($orderId)===5000);$rid=$r->create($orderId,2000,'Partial',null);assert($r->refundableCents($orderId)===3000);$r->markSucceeded($rid,'re_1');assert($r->refundableCents($orderId)===3000);
$rid2=$r->create($orderId,3000,'Rest',null);$r->markSucceeded($rid2,'re_2');assert((new AdminService($db))->order($orderId)['status']==='refunded');
$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,user_id,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents) VALUES('FD-C','cf',{$uid},'preparing','u@example.com','U','S','1','P','AZ','85001','standard','Standard','shipping',1000,1000)");
$cancelOrder=(int)$db->lastInsertId();$cid=$r->requestCancellation($cancelOrder,$uid,'Please cancel');assert($r->pendingCancellation($cancelOrder)['id']==$cid);$r->resolveCancellation($cid,'approved',1);assert($r->pendingCancellation($cancelOrder)===null);
echo "Section 24 checks passed\n";
