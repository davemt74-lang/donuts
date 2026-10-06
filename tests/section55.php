<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');putenv('APP_URL=https://fudgedonuts.example');require $root.'/src/bootstrap.php';

use FudgeDonuts\{CatalogRepository,Database,SeoService};

$db=Database::connection();$db->exec((string)file_get_contents($root.'/database/001_catalog.sql'));
$seo=new SeoService(new CatalogRepository($db),'https://fudgedonuts.example');

assert(SeoService::indexable('/flavor.php?slug=smores')===true);
assert(SeoService::indexable('/preset.php?slug=classic')===true);
assert(SeoService::indexable('/cart.php')===false);
assert(SeoService::indexable('/admin.php')===false);
assert(SeoService::indexable('/order-status.php?token=x')===false);

$flavor=$seo->flavorSchema([
 'name'=>"S'mores",'description'=>'Chocolate and marshmallow','image_path'=>'/images/flavor-smores.png',
 'sold_out'=>0,'slug'=>'smores'
]);
assert($flavor['@type']==='Product');
assert(!isset($flavor['offers']));
assert($flavor['url']==='https://fudgedonuts.example/flavor.php?slug=smores');

$preset=$seo->presetSchema([
 'name'=>'Signature Six','image_path'=>'/images/gift-box.png','total_cents'=>2800,'preset_slug'=>'signature-six','size'=>6
]);
assert($preset['offers']['price']==='28.00');
assert($preset['sku']==='preset-signature-six');

$entries=$seo->sitemapEntries();$paths=array_column($entries,'path');
assert(in_array('/contact.php',$paths,true));
foreach($entries as $entry){assert(isset($entry['changefreq'],$entry['priority']));}

$robots=(string)file_get_contents($root.'/public/robots.php');
assert(str_contains($robots,'Sitemap: '));
assert(str_contains($robots,"'/admin'"));
$ht=(string)file_get_contents($root.'/public/.htaccess');
assert(str_contains($ht,'RewriteRule ^robots\.txt$ robots.php'));

foreach(['index.php','flavor.php','preset.php'] as $file){
 $content=(string)file_get_contents($root.'/public/'.$file);
 assert(str_contains($content,'twitter:card'),$file.' missing Twitter card metadata');
}
echo "Section 55 checks passed\n";
