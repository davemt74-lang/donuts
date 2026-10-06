<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');putenv('APP_KEY=12345678901234567890123456789012');putenv('APP_URL=https://example.com');require $root.'/src/bootstrap.php';

use FudgeDonuts\{CustomerAdminService,Database};

$db=Database::connection();
foreach(['003_accounts.sql','006_orders.sql','013_admin_accounts.sql','022_marketing_consent.sql','023_customer_privacy.sql','027_customer_admin.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));
$db->exec("INSERT INTO users(email,password_hash,first_name,last_name,marketing_opt_in) VALUES('buyer@example.com','x','Buyer','One',1)");
$uid=(int)$db->lastInsertId();
$db->exec("INSERT INTO addresses(user_id,label,first_name,last_name,line1,city,region,postal_code,is_default) VALUES({$uid},'Home','Buyer','One','1 Main','Phoenix','AZ','85001',1)");
$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,user_id,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents) VALUES('FD-C1','c1',{$uid},'completed','buyer@example.com','Buyer','One','1 Main','Phoenix','AZ','85001','standard','Standard Shipping','shipping',2500,2500)");
$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,user_id,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents) VALUES('FD-C2','c2',{$uid},'cancelled','buyer@example.com','Buyer','One','1 Main','Phoenix','AZ','85001','standard','Standard Shipping','shipping',4000,4000)");
$db->exec("INSERT INTO admin_users(email,password_hash,first_name,last_name,role) VALUES('admin@example.com','x','Admin','One','admin')");
$aid=(int)$db->lastInsertId();
$db->exec("INSERT INTO newsletter_subscribers(email,status) VALUES('buyer@example.com','subscribed')");

$svc=new CustomerAdminService($db);
$rows=$svc->customers('buyer');assert(count($rows)===1);assert((int)$rows[0]['order_count']===2);assert((int)$rows[0]['lifetime_spend_cents']===2500);
$c=$svc->customer($uid);assert($c!==null);assert(count($c['addresses'])===1);assert(count($c['orders'])===2);assert($c['marketing_status']==='subscribed');
$note=$svc->addNote($uid,$aid,'Prefers afternoon pickup calls.');assert($note>0);assert(count($svc->notes($uid))===1);
assert($svc->orderForCustomer($uid,(int)$c['orders'][0]['id'])!==null);
$bad=false;try{$svc->addNote($uid,$aid,'');}catch(InvalidArgumentException){$bad=true;}assert($bad);
echo "Section 46 checks passed\n";
