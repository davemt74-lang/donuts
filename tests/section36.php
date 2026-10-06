<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';

use FudgeDonuts\{Database,EmailTemplateService,GuestOrderAccessService,OrderService};

$db=Database::connection();
$db->exec((string)file_get_contents($root.'/database/006_orders.sql'));
$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents) VALUES('FD-GUEST','guestfp','paid','guest@example.com','Guest','Buyer','1 Main','Phoenix','AZ','85001','standard','Standard','shipping',4200,4200)");
$id=(int)$db->lastInsertId();

$order=(new OrderService($db))->findByNumber('FD-GUEST');
assert($order!==null && (int)$order['id']===$id);

$secret='12345678901234567890123456789012';
$access=new GuestOrderAccessService($secret,'https://example.com',30);
$link=$access->link($order,1000);
parse_str((string)parse_url($link,PHP_URL_QUERY),$q);
assert(($q['order']??'')==='FD-GUEST');
assert($access->verify($order,(string)$q['token'],1001)===true);
assert($access->verify($order,(string)$q['token'],1000+(31*86400))===false);

$tampered=(string)$q['token'].'x';
assert($access->verify($order,$tampered,1001)===false);

$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents) VALUES('FD-OTHER','otherfp','paid','other@example.com','Other','Buyer','1 Main','Phoenix','AZ','85001','standard','Standard','shipping',1000,1000)");
$other=(new OrderService($db))->findByNumber('FD-OTHER');
assert($access->verify($other,(string)$q['token'],1001)===false);

[$subject,$text,$html]=(new EmailTemplateService())->orderConfirmation($order,$link);
assert(str_contains($text,'Track your order:'));
assert(str_contains($html,'Track your order'));
assert(str_contains($subject,'FD-GUEST'));

$short=false;try{new GuestOrderAccessService('short','https://example.com',30);}catch(RuntimeException){$short=true;}assert($short);

echo "Section 36 checks passed\n";
