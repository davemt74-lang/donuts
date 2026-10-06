<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');putenv('APP_KEY=section62-test-key-123456789012345');require $root.'/src/bootstrap.php';
use FudgeDonuts\{AdminService,Database,DisputeService,GiftCardService,RefundService};

$db=Database::connection();
foreach(['003_accounts.sql','004_payments.sql','006_orders.sql','013_admin_accounts.sql','016_order_refunds.sql','020_payment_reconciliation.sql','027_tax_configuration.sql','032_gift_cards.sql','033_payment_disputes.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));

$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents) VALUES('FD-DISP','disp1','paid','x@y.com','X','Y','1','Phoenix','AZ','85001','standard','Standard','shipping',1000,1000)");
$orderId=(int)$db->lastInsertId();$db->exec("INSERT INTO order_payment_details(order_id,stripe_payment_intent_id) VALUES({$orderId},'pi_order')");
$svc=new DisputeService($db);
$d=$svc->recordStripeEvent(['id'=>'dp_1','payment_intent'=>'pi_order','charge'=>'ch_1','amount'=>1000,'currency'=>'usd','reason'=>'fraudulent','status'=>'needs_response','evidence_details'=>['due_by'=>time()+86400]],'charge.dispute.created');
assert((int)$d['order_id']===$orderId);assert($svc->stats()['open']===1);assert($svc->hasBlockingDispute($orderId)===true);

$admin=new AdminService($db);$blocked=false;try{$admin->transitionOrder($orderId,'preparing');}catch(RuntimeException|InvalidArgumentException){$blocked=true;}assert($blocked);
$refunds=new RefundService($db);$refundBlocked=false;try{$refunds->create($orderId,500,'Do not double refund',null);}catch(RuntimeException|InvalidArgumentException){$refundBlocked=true;}assert($refundBlocked);

$gift=new GiftCardService($db,'section62-test-key-123456789012345');
$p=$gift->createPurchase(2500,'buyer@example.com','recipient@example.com');$gift->attachPurchaseStripeSession((int)$p['id'],'cs_gift');$svc->attachGiftPurchasePaymentIntent((int)$p['id'],'pi_gift');
$issued=$gift->activatePurchase((int)$p['id'],'cs_gift',2500,'usd');
$svc->recordStripeEvent(['id'=>'dp_gift','payment_intent'=>'pi_gift','charge'=>'ch_gift','amount'=>2500,'currency'=>'usd','reason'=>'fraudulent','status'=>'under_review'],'charge.dispute.created');
assert($gift->card((int)$issued['card']['id'])['status']==='disabled');
$svc->recordStripeEvent(['id'=>'dp_gift','payment_intent'=>'pi_gift','charge'=>'ch_gift','amount'=>2500,'currency'=>'usd','reason'=>'fraudulent','status'=>'won'],'charge.dispute.closed');
assert($gift->card((int)$issued['card']['id'])['status']==='active');

$svc->recordStripeEvent(['id'=>'dp_1','payment_intent'=>'pi_order','charge'=>'ch_1','amount'=>1000,'currency'=>'usd','reason'=>'fraudulent','status'=>'lost'],'charge.dispute.closed');
assert($svc->hasBlockingDispute($orderId)===true);
$svc->recordStripeEvent(['id'=>'dp_1','payment_intent'=>'pi_order','charge'=>'ch_1','amount'=>1000,'currency'=>'usd','reason'=>'fraudulent','status'=>'won'],'charge.dispute.closed');
assert($svc->hasBlockingDispute($orderId)===false);

echo "Section 62 checks passed\n";
