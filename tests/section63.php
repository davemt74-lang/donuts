<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{CustomerCrmService,CustomerPrivacyService,Database};

$db=Database::connection();
foreach(['003_accounts.sql','006_orders.sql','013_admin_accounts.sql','016_order_refunds.sql','023_customer_privacy.sql','028_customer_support.sql','032_gift_cards.sql','034_customer_crm.sql'] as $f){
    $path=$root.'/database/'.$f;if(is_file($path))$db->exec((string)file_get_contents($path));
}
$db->exec("INSERT INTO users(email,password_hash,first_name,last_name) VALUES('buyer@example.com','".password_hash('long-enough-password',PASSWORD_DEFAULT)."','Buyer','One')");$uid=(int)$db->lastInsertId();
$db->exec("INSERT INTO admin_users(email,password_hash,first_name,last_name,role) VALUES('admin@example.com','x','A','D','admin')");$aid=(int)$db->lastInsertId();
$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,user_id,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents) VALUES('FD-C1','c1',{$uid},'completed','Buyer@Example.com','Buyer','One','1','Phoenix','AZ','85001','standard','Standard','shipping',1000,1000)");
$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents) VALUES('FD-C2','c2','completed','buyer@example.com','Buyer','One','1','Phoenix','AZ','85001','standard','Standard','shipping',2000,2000)");
$db->exec("INSERT INTO refund_records(order_id,amount_cents,reason,provider,status) VALUES(1,200,'partial','stripe','succeeded')");
$db->exec("INSERT INTO support_tickets(ticket_number,user_id,email,customer_name,subject,status,priority) VALUES('SUP-C1',{$uid},'buyer@example.com','Buyer One','Help','open','normal')");
$crm=new CustomerCrmService($db);$summary=$crm->summary('buyer@example.com');
assert($summary['orders']===2);assert($summary['gross_cents']===3000);assert($summary['refunded_cents']===200);assert($summary['net_cents']===2800);assert($summary['registered']===true);assert($summary['active_support']===1);
$crm->addTag('buyer@example.com',$aid,'vip');$crm->addNote('buyer@example.com',$aid,'Prefers local pickup.');$profile=$crm->profile('buyer@example.com');assert($profile['tags']===['vip']);assert(count($profile['notes'])===1);assert(count($profile['order_history'])===2);
assert(count($crm->search('FD-C2'))===1);assert(count($crm->search('buyer'))===1);
assert($crm->purgeInternalData('buyer@example.com')===2);assert($crm->profile('buyer@example.com')['notes']===[]);

echo "Section 63 checks passed\n";
