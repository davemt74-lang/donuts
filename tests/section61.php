<?php
declare(strict_types=1);
$root=dirname(__DIR__);
putenv('DB_DSN=sqlite::memory:');
putenv('APP_KEY=section61-test-key-abcdefghijklmnopqrstuvwxyz-123456');
putenv('GIFT_CARD_AMOUNTS_CENTS=2500,5000,10000');
require $root.'/src/bootstrap.php';

use FudgeDonuts\{Database,GiftCardService,MigrationService,OrderService,RefundService,TaxService};

$db=Database::connection();
$migrations=new MigrationService($db,$root.'/database');
$migrations->applyPending();$migrations->assertClean();

$gift=new GiftCardService($db,(string)env('APP_KEY',''));
$purchase=$gift->createPurchase(2500,'buyer@example.com','recipient@example.com','Recipient','Enjoy something sweet.');
$issued=$gift->activatePurchase((int)$purchase['id'],'cs_gift_1',2500,'usd');
assert(str_starts_with($issued['code'],'FDGC-'));
assert((int)$issued['card']['balance_cents']===2500);
assert($issued['card']['recipient_email']==='recipient@example.com');

$repeat=$gift->activatePurchase((int)$purchase['id'],'cs_gift_1',2500,'usd');
assert($repeat['code']===$issued['code']);
assert((int)$db->query('SELECT COUNT(*) FROM gift_cards')->fetchColumn()===1);
$stored=$db->query('SELECT code_cipher,code_hash FROM gift_cards LIMIT 1')->fetch();
assert(!str_contains((string)$stored['code_cipher'],'FDGC'));
assert(hash('sha256',preg_replace('/[^A-Z0-9]/','',strtoupper($issued['code'])))===$stored['code_hash']);
$found=$gift->findByCode(strtolower($issued['code']));assert((int)$found['id']===(int)$issued['card']['id']);

$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,status,email,first_name,last_name,line1,city,region,postal_code,country,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,shipping_cents,total_cents,currency) VALUES('FD-GC1','gc-order-1','pending_payment','buyer@example.com','Buyer','One','1 Main','Phoenix','AZ','85001','US','standard','Standard','shipping',3500,500,4000,'usd')");
$orderId=(int)$db->lastInsertId();
$orderService=new OrderService($db);$orderService->attachStripeSession($orderId,'cs_order_gc_1');
$tax=new TaxService($db);$tax->snapshotOrder($orderId);$tax->recordCalculated($orderId,320);

$app=$gift->reserveForOrder((int)$issued['card']['id'],$orderId,4320);
assert((int)$app['reserved_cents']===2500);
assert((int)$gift->card((int)$issued['card']['id'])['available_cents']===0);
assert($orderService->markPaidByExternalTender('cs_order_gc_1',1820,2500,320,'usd')===true);
assert($gift->redeemForOrder($orderId)===2500);
$paid=$orderService->find($orderId);assert($paid['status']==='paid');assert((int)$paid['tax_cents']===320);assert((int)$paid['total_cents']===4320);
$card=$gift->card((int)$issued['card']['id']);assert((int)$card['balance_cents']===0);assert($card['status']==='depleted');

$refunds=new RefundService($db);
$refundId=$refunds->create($orderId,1000,'Partial refund',null);
$r=$refunds->refund($refundId);
assert((int)$r['gift_card_amount_cents']===1000);assert((int)$r['stripe_amount_cents']===0);
assert($gift->refundForOrder($orderId,1000,'refund:'.$refundId)===1000);
$refunds->markSucceeded($refundId,null);
$balance=(int)$gift->card((int)$issued['card']['id'])['balance_cents'];assert($balance===1000);
assert($gift->refundForOrder($orderId,1000,'refund:'.$refundId)===1000);
assert((int)$gift->card((int)$issued['card']['id'])['balance_cents']===$balance);

$refund2=$refunds->create($orderId,2000,'Mixed refund',null);$r2=$refunds->refund($refund2);
assert((int)$r2['gift_card_amount_cents']===1500);assert((int)$r2['stripe_amount_cents']===500);
assert($refunds->refundableCents($orderId)===1320);

$gift->setStatus((int)$issued['card']['id'],'disabled');assert($gift->card((int)$issued['card']['id'])['status']==='disabled');
$gift->setStatus((int)$issued['card']['id'],'active');assert($gift->card((int)$issued['card']['id'])['status']==='active');
$liability=$gift->liability();assert((int)$liability['balance_cents']===1000);

foreach([
    'public/gift-cards.php'=>'metadata[purchase_type]',
    'public/stripe-webhook.php'=>'gift_card_redemption_failure',
    'public/checkout-review.php'=>'gift_card_apply',
    'public/pay.php'=>'markPaidByExternalTender',
    'public/admin-gift-cards.php'=>'Outstanding balance',
] as $file=>$needle){
    assert(str_contains((string)file_get_contents($root.'/'.$file),$needle),$file.' missing '.$needle);
}

echo "Section 61 checks passed\n";
