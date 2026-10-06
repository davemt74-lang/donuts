<?php
declare(strict_types=1);$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{Database,GiftService};
$db=Database::connection();$db->exec((string)file_get_contents($root.'/database/006_orders.sql'));$db->exec((string)file_get_contents($root.'/database/011_gifts.sql'));
$g=new GiftService($db);$x=$g->normalizeCheckout(['is_gift'=>1,'gift_packaging'=>'gift-box','hide_price'=>1,'gift_delivery_date'=>gmdate('Y-m-d'),'gift_recipient_email'=>'R@EXAMPLE.COM']);assert($x['hide_price']===true);assert($x['gift_recipient_email']==='r@example.com');
$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents) VALUES('FD-G','fg','x@y.com','X','Y','1','P','AZ','85001','standard','Standard','shipping',1000,1000)");
$id=(int)$db->lastInsertId();$g->persist($id,$x);assert($g->forOrder($id)['packaging']==='gift-box');
echo "Section 15 checks passed\n";
