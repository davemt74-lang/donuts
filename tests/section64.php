<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');putenv('APP_KEY=section64-test-key-12345678901234567890');putenv('APP_URL=https://example.test');require $root.'/src/bootstrap.php';
use FudgeDonuts\{CheckoutRecoveryService,Database};

$db=Database::connection();
foreach(['003_accounts.sql','006_orders.sql','010_content.sql','022_marketing_consent.sql','025_job_monitoring.sql','035_checkout_recovery.sql'] as $f){$p=$root.'/database/'.$f;if(is_file($p))$db->exec((string)file_get_contents($p));}
$svc=new CheckoutRecoveryService($db,'section64-test-key-12345678901234567890','https://example.test',7);
$cart=['abc'=>['kind'=>'custom','size'=>3,'selections'=>[1=>3],'quantity'=>1]];
$id=$svc->capture(null,null,'buyer@example.com',$cart,'SAVE10');$row=$db->query("SELECT * FROM checkout_recoveries WHERE id={$id}")->fetch();$link=$svc->link($row);assert(str_contains($link,'recover-checkout.php'));
parse_str((string)parse_url($link,PHP_URL_QUERY),$q);$resolved=$svc->resolve((int)$q['id'],(int)$q['expires'],(string)$q['sig']);assert($resolved['cart']===$cart);assert($resolved['coupon']==='SAVE10');
$tampered=false;try{$svc->resolve((int)$q['id'],(int)$q['expires'],(string)$q['sig'].'x');}catch(InvalidArgumentException){$tampered=true;}assert($tampered);
$svc->markRecovered($id);assert($db->query("SELECT status FROM checkout_recoveries WHERE id={$id}")->fetchColumn()==='recovered');
$db->exec("INSERT INTO newsletter_subscribers(email,status) VALUES('buyer@example.com','subscribed')");
$db->exec("UPDATE checkout_recoveries SET status='active',created_at=datetime('now','-3 hours') WHERE id={$id}");assert(count($svc->dueForReminder(2))===1);
$svc->markReminderSent($id);assert(count($svc->dueForReminder(2))===0);
$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents) VALUES('FD-REC','rec1','paid','buyer@example.com','B','U','1','Phoenix','AZ','85001','standard','Standard','shipping',1000,1000)");$orderId=(int)$db->lastInsertId();
$svc->attachOrder($id,$orderId);$svc->markConvertedByOrder($orderId);assert($db->query("SELECT status FROM checkout_recoveries WHERE id={$id}")->fetchColumn()==='converted');assert((int)$db->query("SELECT converted_order_id FROM checkout_recoveries WHERE id={$id}")->fetchColumn()===$orderId);

$id2=$svc->capture(null,null,'no-consent@example.com',$cart,null);$db->exec("UPDATE checkout_recoveries SET created_at=datetime('now','-3 hours') WHERE id={$id2}");assert(count($svc->dueForReminder(2))===0);
$db->exec("INSERT INTO newsletter_subscribers(email,status) VALUES('no-consent@example.com','unsubscribed')");assert(count($svc->dueForReminder(2))===0);
$id3=$svc->capture(null,null,'expired@example.com',$cart,null);$db->exec("UPDATE checkout_recoveries SET expires_at=datetime('now','-1 minute') WHERE id={$id3}");assert($svc->expire()===1);assert($db->query("SELECT status FROM checkout_recoveries WHERE id={$id3}")->fetchColumn()==='expired');
echo "Section 64 checks passed\n";
