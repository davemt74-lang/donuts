<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{AnalyticsService,Database};

$db=Database::connection();
foreach(['006_orders.sql','029_conversion_analytics.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));
$a=new AnalyticsService($db);$v='0123456789abcdef0123456789abcdef';
$a->record($v,'page_view','/?utm_source=email',['utm_source'=>'newsletter','utm_medium'=>'email','utm_campaign'=>'fall','referrer_host'=>'mail.example.com']);
$a->record($v,'builder_view','/builder.php',[]);
$a->record($v,'cart_view','/cart.php',[]);
$a->record($v,'checkout_view','/checkout.php',[]);
$a->record($v,'page_view','/fall-search',['utm_source'=>'google','utm_medium'=>'cpc','utm_campaign'=>'search']);
$db->exec("INSERT INTO orders(order_number,checkout_fingerprint,status,email,first_name,last_name,line1,city,region,postal_code,fulfillment_code,fulfillment_name,fulfillment_type,subtotal_cents,total_cents) VALUES('FD-A1','a1','paid','x@y.com','X','Y','1','Phoenix','AZ','85001','standard','Standard','shipping',2000,2000)");
$orderId=(int)$db->lastInsertId();
$a->attributeOrder($orderId,$v);$a->recordPurchase($orderId);$a->recordPurchase($orderId);
$f=$a->funnel(30);assert($f['visitors']===1);assert($f['builder_visitors']===1);assert($f['checkout_visitors']===1);assert($f['purchases']===1);assert($f['revenue_cents']===2000);assert($f['conversion_rate']===100.0);
$s=$a->sources(30);assert(count($s)===1);assert($s[0]['source']==='google');assert($s[0]['medium']==='cpc');assert($s[0]['campaign']==='search');
$row=$db->query('SELECT * FROM analytics_visitors')->fetch();assert($row['first_utm_campaign']==='fall');assert($row['last_utm_campaign']==='search');assert($row['last_utm_source']==='google');
$bad=false;try{$a->record('bad','page_view','/',[]);}catch(InvalidArgumentException){$bad=true;}assert($bad);
foreach(['public/index.php','public/builder.php','public/cart.php','public/checkout.php','public/checkout-review.php'] as $file)assert(str_contains((string)file_get_contents($root.'/'.$file),'analytics_script()'));
assert(str_contains((string)file_get_contents($root.'/public/assets/analytics.js'),"host!==location.hostname"));
echo "Section 56 checks passed\n";
