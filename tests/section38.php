<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');putenv('APP_KEY=12345678901234567890123456789012');putenv('APP_URL=https://example.com');require $root.'/src/bootstrap.php';

use FudgeDonuts\{AuthService,CustomerPrivacyService,Database,MarketingConsentService};

$db=Database::connection();
foreach(['003_accounts.sql','006_orders.sql','010_content.sql','022_marketing_consent.sql','023_customer_privacy.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));

$auth=new AuthService($db);
$uid=$auth->register('privacy@example.com','Password123','Privacy','User');
$auth->saveAddress($uid,['label'=>'Home','first_name'=>'Privacy','last_name'=>'User','line1'=>'1 Main','city'=>'Phoenix','region'=>'AZ','postal_code'=>'85001','is_default'=>1]);
(new MarketingConsentService($db,'12345678901234567890123456789012','https://example.com'))->subscribe('privacy@example.com','test',true);

$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,user_id,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents,stripe_checkout_session_id) VALUES('FD-PRIV','pfpriv',{$uid},'completed','privacy@example.com','Privacy','User','1 Main','Phoenix','AZ','85001','standard','Standard','shipping',4200,4200,'cs_internal_secret')");
$orderId=(int)$db->lastInsertId();
$db->exec("INSERT INTO order_items(order_id,kind,pack_size,quantity,unit_price_cents,line_total_cents,configuration_json) VALUES({$orderId},'custom',6,1,4200,4200,'{}')");

$svc=new CustomerPrivacyService($db);
assert($svc->verifyPassword($uid,'Password123')===true);
assert($svc->verifyPassword($uid,'wrong')===false);
$export=$svc->exportData($uid);
assert($export['account']['email']==='privacy@example.com');
assert(count($export['addresses'])===1);
assert(count($export['orders'])===1);
assert(!array_key_exists('checkout_fingerprint',$export['orders'][0]));
assert(!array_key_exists('stripe_checkout_session_id',$export['orders'][0]));
assert(count($export['orders'][0]['items'])===1);
assert((int)$db->query("SELECT COUNT(*) FROM customer_privacy_events WHERE action='data_export'")->fetchColumn()===1);

$uid2=$auth->register('active@example.com','Password123','Active','Buyer');
$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,user_id,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents) VALUES('FD-ACTIVE','pfactive',{$uid2},'paid','active@example.com','Active','Buyer','2 Main','Phoenix','AZ','85001','standard','Standard','shipping',1000,1000)");
$blocked=false;try{$svc->closeAccount($uid2,'Password123');}catch(RuntimeException){$blocked=true;}assert($blocked);assert($auth->user($uid2)!==null);

$svc->closeAccount($uid,'Password123');
assert($auth->user($uid)===null);
assert((int)$db->query("SELECT COUNT(*) FROM addresses WHERE user_id={$uid}")->fetchColumn()===0);
assert($db->query("SELECT user_id FROM orders WHERE id={$orderId}")->fetchColumn()===null);
assert($db->query("SELECT status FROM newsletter_subscribers WHERE email='privacy@example.com'")->fetchColumn()==='unsubscribed');
assert((int)$db->query("SELECT COUNT(*) FROM customer_privacy_events WHERE action='account_closed'")->fetchColumn()===1);

echo "Section 38 checks passed\n";
