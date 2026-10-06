<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{CatalogRepository,Database};
$db=Database::connection();$db->exec((string)file_get_contents($root.'/database/001_catalog.sql'));$c=new CatalogRepository($db);
$f=$c->flavorBySlug('smores');assert($f!==null);assert($f['name']==="S'mores");
$c->saveFlavor(['id'=>$f['id'],'name'=>"S'mores",'slug'=>'smores','description'=>'Test','surcharge_cents'=>0,'image_path'=>'/images/x.png','ingredients'=>'Chocolate, marshmallow, graham','allergens'=>'Milk, wheat','active'=>1,'sold_out'=>0,'seasonal'=>0,'sort_order'=>10]);
$f=$c->flavorBySlug('smores');assert($f['ingredients']==='Chocolate, marshmallow, graham');assert($f['allergens']==='Milk, wheat');
$db->exec("UPDATE flavors SET active=0 WHERE slug='smores'");assert($c->flavorBySlug('smores')===null);assert($c->flavorBySlug('smores',false)!==null);
echo "Section 27 checks passed\n";
