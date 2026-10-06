<?php
declare(strict_types=1);$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{Database,ShippingService};
$db=Database::connection();$db->exec((string)file_get_contents($root.'/database/005_shipping.sql'));
$db->exec("INSERT INTO pickup_zip_codes(postal_code) VALUES('85001'),('85004')");
$s=new ShippingService($db);
$m=$s->methodsFor('85001',5000);assert(count($m)===2);assert($m[0]['price_cents']===899);assert($m[1]['type']==='pickup');
$m=$s->methodsFor('85001-1234',7000);assert($m[0]['price_cents']===0);
$m=$s->methodsFor('90210',5000);assert(count($m)===1);
$bad=false;try{$s->quote('local-pickup','90210',5000);}catch(InvalidArgumentException){$bad=true;}assert($bad);
echo "Section 8 checks passed\n";
