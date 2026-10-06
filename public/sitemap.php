<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{CatalogRepository,Database,SeoService};

header('Content-Type: application/xml; charset=UTF-8');
$seo=new SeoService(new CatalogRepository(Database::connection()),(string)env('APP_URL','https://example.com'));
echo '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
foreach($seo->sitemapEntries() as $entry){
    echo '<url><loc>'.htmlspecialchars($seo->canonical($entry['path']),ENT_XML1|ENT_QUOTES,'UTF-8').'</loc><changefreq>'.htmlspecialchars($entry['changefreq'],ENT_XML1).'</changefreq><priority>'.htmlspecialchars($entry['priority'],ENT_XML1).'</priority></url>';
}
echo '</urlset>';
