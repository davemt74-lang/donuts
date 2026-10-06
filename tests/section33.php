<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';

use FudgeDonuts\{Database,OrderService,PaymentReconciliationService,PaymentRepository,RefundService};

$db=Database::connection();
foreach(['004_payments.sql','006_orders.sql','013_admin_accounts.sql','016_order_refunds.sql','020_payment_reconciliation.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));

$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,shipping_cents,total_cents,currency,stripe_checkout_session_id) VALUES('FD-T1','taxf1','pending_payment','x@y.com','X','Y','1','P','AZ','85001','standard','Standard','shipping',4000,900,4900,'usd','cs_match')");
$matchId=(int)$db->lastInsertId();
$o=new OrderService($db);
assert($o->markPaidByStripeSession('cs_match',4900,5300,400,'usd')===true);
$paid=$o->find($matchId);assert($paid['status']==='paid');assert((int)$paid['tax_cents']===400);assert((int)$paid['total_cents']===5300);assert($paid['payment_reconciliation']['status']==='matched');

$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,shipping_cents,total_cents,currency,stripe_checkout_session_id) VALUES('FD-T2','taxf2','pending_payment','x@y.com','X','Y','1','P','AZ','85001','standard','Standard','shipping',4000,900,4900,'usd','cs_bad')");
$reviewId=(int)$db->lastInsertId();
assert($o->markPaidByStripeSession('cs_bad',4800,5200,400,'usd')===false);
$review=$o->find($reviewId);assert($review['status']==='payment_review');assert((int)$review['tax_cents']===0);assert((int)$review['total_cents']===4900);assert($review['payment_reconciliation']['status']==='mismatch');

$db->exec("INSERT INTO order_payment_details(order_id,stripe_payment_intent_id) VALUES({$reviewId},'pi_review')");
$db->exec("INSERT INTO admin_users(email,password_hash,first_name,last_name,role) VALUES('a@example.com','x','A','D','super_admin')");
$refunds=new RefundService($db);assert($refunds->refundableCents($reviewId)===4900);

$r=new PaymentReconciliationService($db);
$currency=$r->reconcile($reviewId,'cs_bad',['amount_subtotal'=>4900,'amount_total'=>5300,'currency'=>'eur','total_details'=>['amount_tax'=>400]]);
assert($currency['matched']===false);assert(in_array('currency_mismatch',$currency['reasons'],true));

$payments=new PaymentRepository($db);$attempt=$payments->createSession(null,4900,['order_id'=>$reviewId]);$payments->attachProviderSession((int)$attempt['id'],'cs_bad');$payments->markReviewByProviderSession('cs_bad');
$status=$db->query("SELECT status FROM payment_sessions WHERE id=".(int)$attempt['id'])->fetchColumn();assert($status==='review');

echo "Section 33 checks passed\n";
