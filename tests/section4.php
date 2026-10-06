<?php
declare(strict_types=1);
$root=dirname(__DIR__);
putenv('DB_DSN=sqlite::memory:');
require $root.'/src/bootstrap.php';

use FudgeDonuts\{CartService,CatalogRepository,Database,DiscountService,PackBuilderService};

$db=Database::connection();
$db->exec((string)file_get_contents($root.'/database/001_catalog.sql'));
$db->exec((string)file_get_contents($root.'/database/002_discounts.sql'));
$catalog=new CatalogRepository($db);
$ids=array_column($catalog->flavors(),'id','slug');
$cart=new CartService(new PackBuilderService($catalog),new DiscountService($db));
$session=[];

$key=$cart->addCustomBox($session,3,[$ids['smores']=>3],3);
$sum=$cart->summary($session);
assert($sum['units']===3);
assert($sum['subtotal_cents']===3897);
assert($sum['discount_cents']===194);
assert($sum['total_cents']===3703);

$db->exec("INSERT INTO discount_rules(code,name,type,value,min_units,min_subtotal_cents,active,sort_order) VALUES('TENOFF','Ten dollars off','fixed',1000,0,2000,1,1)");
$sum=$cart->summary($session,'TENOFF');
assert($sum['discount_cents']===1000);
assert($sum['total_cents']===2897);

$cart->setQuantity($session,$key,6);
$sum=$cart->summary($session);
assert($sum['discount_cents']===779);

$db->exec("UPDATE flavors SET surcharge_cents=200 WHERE slug='smores'");
$sum=$cart->summary($session);
assert($sum['subtotal_cents']===11394);

$cart->remove($session,$key);
assert($cart->summary($session)['items']===[]);

echo "Section 4 checks passed\n";
