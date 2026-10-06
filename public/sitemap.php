<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{CatalogRepository,Database,SeoService};

header('Content-Type: application/xml; charset=UTF-8');
$base=rtrim((string)env('APP_URL','https://example.com'),'/');
$seo=new SeoService(new CatalogRepository(Database::connection()),$base);
echo '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
foreach($seo->sitemapPaths() as $path)echo '<url><loc>'.htmlspecialchars($seo->canonical($path),ENT_XML1).'</loc></url>';
echo '</urlset>';
