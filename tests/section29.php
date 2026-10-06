<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{CatalogRepository,Database,SeoService};
$db=Database::connection();foreach(['001_catalog.sql','014_preset_defaults.sql'] as $f)$db->exec((string)file_get_contents($root.'/database/'.$f));
$c=new CatalogRepository($db);$seo=new SeoService($c,'https://example.com/');
assert($seo->canonical('/flavor.php?slug=smores')==='https://example.com/flavor.php?slug=smores');
$f=$c->flavorBySlug('smores');$schema=$seo->flavorSchema($f);assert($schema['@type']==='Product');assert(str_contains($schema['offers']['url'],'smores'));
$paths=$seo->sitemapPaths();assert(in_array('/builder.php?size=3',$paths,true));assert(in_array('/flavor.php?slug=smores',$paths,true));assert(in_array('/preset.php?slug=classic-trio',$paths,true));
echo "Section 29 checks passed\n";
