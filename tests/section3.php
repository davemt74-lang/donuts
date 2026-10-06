<?php
declare(strict_types=1);

$root=dirname(__DIR__);
putenv('DB_DSN=sqlite::memory:');
require $root.'/src/bootstrap.php';

use FudgeDonuts\CatalogRepository;
use FudgeDonuts\Database;
use FudgeDonuts\PackBuilderService;

$db=Database::connection();
$db->exec((string)file_get_contents($root.'/database/001_catalog.sql'));
$catalog=new CatalogRepository($db);
$builder=new PackBuilderService($catalog);
$ids=array_column($catalog->flavors(),'id','slug');

foreach([3,6,12] as $size){
    $box=$builder->build($size,[$ids['smores']=>$size]);
    assert($box['size']===$size);
    assert($box['total_cents']===(int)$catalog->packBySize($size)['base_price_cents']);
}
$box=$builder->build(6,[$ids['caramel-pretzel']=>2,$ids['cookies-cream']=>2,$ids['mint-chocolate']=>2]);
assert($box['surcharge_cents']===400);
assert(count($box['items'])===3);

$bad=false;
try{$builder->build(3,[$ids['smores']=>4]);}catch(InvalidArgumentException){$bad=true;}
assert($bad);

$db->exec("UPDATE flavors SET sold_out=1 WHERE slug='smores'");
$bad=false;
try{$builder->build(3,[$ids['smores']=>3]);}catch(InvalidArgumentException){$bad=true;}
assert($bad);

echo "Section 3 checks passed\n";
