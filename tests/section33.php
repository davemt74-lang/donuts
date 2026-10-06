<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{Database,OrderService,PaymentReconciliationService};
$db=Database::connection();foreach(['006_orders.sql','020_payment_reconciliation.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));
$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,shipping_cents,total_cents,currency,stripe_checkout_session_id) VALUES('FD-T','taxf','pending_payment','x@y.com','X','Y','1','P','AZ','85001','standard','Standard','shipping',4000,900,4900,'usd','cs_tax')");
$id=(int)$db->lastInsertId();$r=new PaymentReconciliationService($db);
$ok=$r->reconcile($id,'cs_tax',['amount_subtotal'=>4900,'amount_total'=>5300,'currency'=>'usd','total_details'=>['amount_tax'=>400]]);assert($ok['matched']===true);assert($ok['stripe_total_cents']===5300);
$bad=$r->reconcile($id,'cs_tax',['amount_subtotal'=>4800,'amount_total'=>5200,'currency'=>'usd','total_details'=>['amount_tax'=>400]]);assert($bad['matched']===false);assert(in_array('pre_tax_total_mismatch',$bad['reasons'],true));
$currency=$r->reconcile($id,'cs_tax',['amount_subtotal'=>4900,'amount_total'=>5300,'currency'=>'eur','total_details'=>['amount_tax'=>400]]);assert($currency['matched']===false);assert(in_array('currency_mismatch',$currency['reasons'],true));
$o=new OrderService($db);$o->markPaymentReviewByStripeSession('cs_tax','Tax/total reconciliation mismatch.');assert($o->find($id)['status']==='payment_review');
echo "Section 33 checks passed\n";
