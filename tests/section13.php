<?php
declare(strict_types=1);$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{Database,PromotionService};
$db=Database::connection();foreach(['002_discounts.sql','006_orders.sql','009_promotions.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));
$p=new PromotionService($db);$id=$p->save(['name'=>'VIP','code'=>'vip20','type'=>'percent','value'=>20,'active'=>1,'usage_limit'=>1]);assert($id>0);
$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents) VALUES('FD-X','f','x@y.com','X','Y','1','P','AZ','85001','standard','Standard','shipping',1000,1000)");
$orderId=(int)$db->lastInsertId();$p->recordOrderDiscounts($orderId,[['id'=>$id,'name'=>'VIP','amount_cents'=>200]]);$p->redeemOrder($orderId);$p->redeemOrder($orderId);
$count=(int)$db->query('SELECT usage_count FROM discount_rules WHERE id='.$id)->fetchColumn();assert($count===1);
echo "Section 13 checks passed\n";
