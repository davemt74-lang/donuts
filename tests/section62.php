<?php
declare(strict_types=1);
$root=dirname(__DIR__);
putenv('DB_DSN=sqlite::memory:');
putenv('APP_KEY=section62-test-key-abcdefghijklmnopqrstuvwxyz-123456');
require $root.'/src/bootstrap.php';

use FudgeDonuts\{CustomerPrivacyService,Database,LoyaltyService,MigrationService,RefundService};

$db=Database::connection();
$migrations=new MigrationService($db,$root.'/database');$migrations->applyPending();$migrations->assertClean();

$db->exec("INSERT INTO users(email,password_hash,first_name,last_name) VALUES('rewards@example.com','".password_hash('long-enough-password',PASSWORD_DEFAULT)."','Reward','User')");
$userId=(int)$db->lastInsertId();

$loyalty=new LoyaltyService($db);
$loyalty->saveSettings([
    'enabled'=>1,
    'points_per_dollar'=>10,
    'cents_per_point'=>1,
    'minimum_redeem_points'=>10,
    'maximum_redeem_percent'=>100,
]);

$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,user_id,status,email,first_name,last_name,line1,city,region,postal_code,country,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,discount_cents,shipping_cents,tax_cents,total_cents,currency) VALUES('FD-R1','r1',{$userId},'paid','rewards@example.com','Reward','User','1 Main','Phoenix','AZ','85001','US','standard','Standard','shipping',5000,0,0,0,5000,'usd')");
$order1=(int)$db->lastInsertId();
assert($loyalty->earnForOrder($order1)===500);
assert($loyalty->earnForOrder($order1)===500);
$a=$loyalty->account($userId);assert((int)$a['points_balance']===500);assert((int)$a['available_points']===500);

$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,user_id,status,email,first_name,last_name,line1,city,region,postal_code,country,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,discount_cents,shipping_cents,tax_cents,total_cents,currency) VALUES('FD-R2','r2',{$userId},'pending_payment','rewards@example.com','Reward','User','1 Main','Phoenix','AZ','85001','US','standard','Standard','shipping',3000,0,0,0,3000,'usd')");
$order2=(int)$db->lastInsertId();
$res=$loyalty->reserveForOrder($userId,$order2,200,3000);
assert((int)$res['reserved_points']===200);assert((int)$res['discount_cents']===200);
$o=$db->query("SELECT discount_cents,total_cents FROM orders WHERE id={$order2}")->fetch();assert((int)$o['discount_cents']===200);assert((int)$o['total_cents']===2800);
assert((int)$loyalty->account($userId)['reserved_points']===200);

$loyalty->releaseForOrder($order2);assert((int)$loyalty->account($userId)['reserved_points']===0);
$res2=$loyalty->reserveForOrder($userId,$order2,200,3000);assert($res2['status']==='reserved');
$o=$db->query("SELECT discount_cents,total_cents FROM orders WHERE id={$order2}")->fetch();assert((int)$o['discount_cents']===200);assert((int)$o['total_cents']===2800);

$db->exec("UPDATE orders SET status='paid' WHERE id={$order2}");
assert($loyalty->commitRedemption($order2)===200);
assert($loyalty->commitRedemption($order2)===200);
assert((int)$loyalty->account($userId)['points_balance']===300);
assert($loyalty->earnForOrder($order2)===280);
assert((int)$loyalty->account($userId)['points_balance']===580);

$refunds=new RefundService($db);
$refund1=$refunds->create($order2,1400,'Half refund',null);$refunds->markSucceeded($refund1,null);
$a=$loyalty->account($userId);assert((int)$a['points_balance']===540);
$row=$loyalty->orderRow($order2);assert((int)$row['restored_points']===100);assert((int)$row['reversed_points']===140);

$refund2=$refunds->create($order2,1400,'Remaining refund',null);$refunds->markSucceeded($refund2,null);
$a=$loyalty->account($userId);assert((int)$a['points_balance']===500);
$row=$loyalty->orderRow($order2);assert((int)$row['restored_points']===200);assert((int)$row['reversed_points']===280);
$refunds->markSucceeded($refund2,null);assert((int)$loyalty->account($userId)['points_balance']===500);

$after=$loyalty->adjustByEmail('rewards@example.com',-700,'Correction',1);assert((int)$after['points_balance']===-200);assert((int)$after['available_points']===0);
$blocked=false;try{$loyalty->quoteRedemption($userId,10,1000);}catch(InvalidArgumentException){$blocked=true;}assert($blocked);

$export=(new CustomerPrivacyService($db))->exportData($userId);
assert(array_key_exists('rewards',$export));assert((int)$export['rewards']['account']['points_balance']===-200);assert(count($export['rewards']['ledger'])>=5);

$stats=$loyalty->programStats();assert($stats['members']===1);assert($stats['positive_points']===0);assert($stats['liability_cents']===0);
assert(count($loyalty->recentLedger())>=5);

foreach([
    'public/checkout-review.php'=>'loyalty_apply',
    'public/pay.php'=>'reserveForOrder',
    'public/stripe-webhook.php'=>'loyalty_redemption_failure',
    'scripts/recover-reservations.php'=>'loyalty_earn_recovery_failure',
    'public/admin-loyalty.php'=>'Outstanding points',
    'public/account.php'=>'Lifetime earned',
] as $file=>$needle){
    assert(str_contains((string)file_get_contents($root.'/'.$file),$needle),$file.' missing '.$needle);
}

echo "Section 62 checks passed\n";
