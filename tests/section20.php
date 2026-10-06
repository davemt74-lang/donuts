<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{CartService,CatalogRepository,Database,DiscountService,PackBuilderService,PresetPackService};
$db=Database::connection();foreach(['001_catalog.sql','002_discounts.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));
$c=new CatalogRepository($db);$packs=$c->packs();$flavors=$c->flavors(false);$ids=array_column($flavors,'id','slug');
$p3=$c->packBySize(3);$presetId=$c->savePreset(['name'=>'Classic Trio','slug'=>'classic-trio','pack_size_id'=>$p3['id'],'active'=>1,'items'=>[$ids['smores']=>1,$ids['cookies-cream']=>1,$ids['mint-chocolate']=>1]]);
assert($presetId>0);$box=(new PresetPackService($c))->buildBySlug('classic-trio');assert($box['size']===3);assert(count($box['items'])===3);
$session=[];$cart=new CartService(new PackBuilderService($c),new DiscountService($db),new PresetPackService($c));$cart->addPresetBox($session,'classic-trio',2);$sum=$cart->summary($session);assert($sum['units']===2);assert($sum['items'][0]['box']['type']==='preset');
$c->savePack(['id'=>$p3['id'],'name'=>'Three Pack','size'=>3,'base_price_cents'=>1499,'customizable'=>1,'active'=>1]);assert((int)$c->packBySize(3)['base_price_cents']===1499);
$c->setEligibility((int)$p3['id'],[(int)$ids['smores']]);assert($c->eligibleFlavorIds((int)$p3['id'])===[(int)$ids['smores']]);
echo "Section 20 checks passed\n";
