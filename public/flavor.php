<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{CatalogRepository,Database,SeoService};

$catalog=new CatalogRepository(Database::connection());
$slug=trim((string)($_GET['slug']??''));
$flavor=$catalog->flavorBySlug($slug);
if(!$flavor){
    \FudgeDonuts\HttpResponseService::send(
        404,
        'That flavor isn’t available.',
        'The flavor link may be old, seasonal, or no longer active.',
        [
            ['label'=>'See current flavors','href'=>'/#flavors'],
            ['label'=>'Build a box','href'=>'/builder.php?size=12']
        ]
    );
}
$seo=new SeoService($catalog,(string)env('APP_URL','https://example.com'));$schema=json_encode($seo->flavorSchema($flavor),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$canonical=$seo->canonical('/flavor.php?slug='.rawurlencode($slug));
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=htmlspecialchars($flavor['name'])?> · Fudge Donuts</title><meta name="description" content="<?=htmlspecialchars($flavor['description'])?>"><link rel="canonical" href="<?=htmlspecialchars($canonical)?>"><meta property="og:title" content="<?=htmlspecialchars($flavor['name'])?> · Fudge Donuts"><meta property="og:description" content="<?=htmlspecialchars($flavor['description'])?>"><meta property="og:type" content="product"><meta property="og:url" content="<?=htmlspecialchars($canonical)?>"><meta property="og:image" content="<?=htmlspecialchars($seo->canonical($flavor['image_path']?:'/images/placeholder.png'))?>"><meta property="og:site_name" content="Fudge Donuts"><meta name="twitter:card" content="summary_large_image"><meta name="twitter:title" content="<?=htmlspecialchars($flavor['name'])?> · Fudge Donuts"><meta name="twitter:description" content="<?=htmlspecialchars($flavor['description'])?>"><meta name="twitter:image" content="<?=htmlspecialchars($seo->canonical($flavor['image_path']?:'/images/placeholder.png'))?>"><script type="application/ld+json"><?=$schema?></script><link rel="stylesheet" href="<?=htmlspecialchars(asset_url('/assets/app.css'))?>"><?=analytics_script()?></head><body>
<a class="skip-link" href="#main-content">Skip to main content</a>
<header class="nav"><a class="brand" href="/">Fudge Donuts</a><nav><a href="/#flavors">Flavors</a><a href="/builder.php?size=12">Build a Box</a><a href="/cart.php">Cart</a></nav></header>
<main id="main-content" class="section flavor-detail"><div class="flavor-detail-grid"><div><img class="flavor-detail-image" src="<?=htmlspecialchars($flavor['image_path']?:'/images/placeholder.png')?>" alt="<?=htmlspecialchars($flavor['name'])?>" decoding="async" fetchpriority="high"></div><div><p class="eyebrow"><?=!empty($flavor['seasonal'])?'Seasonal flavor':'Fudge Donut flavor'?></p><h1><?=htmlspecialchars($flavor['name'])?></h1><p class="lead"><?=htmlspecialchars($flavor['description'])?></p><?php if((int)$flavor['sold_out']):?><div class="notice error">Currently sold out.</div><?php endif;?><?php if((int)$flavor['surcharge_cents']):?><p><strong>Premium flavor:</strong> +<?=money((int)$flavor['surcharge_cents'])?> per donut in custom boxes.</p><?php endif;?><div class="food-info"><section><h2>Ingredients</h2><p><?=trim((string)$flavor['ingredients'])!==''?nl2br(htmlspecialchars($flavor['ingredients'])):'Ingredient information coming soon.'?></p></section><section><h2>Allergens</h2><p><?=trim((string)$flavor['allergens'])!==''?nl2br(htmlspecialchars($flavor['allergens'])):'No allergen statement has been entered yet. Contact us before ordering if you have a food allergy.'?></p></section></div><div class="allergen-callout"><strong>Food allergy notice</strong><p>Products may be prepared in a shared kitchen. Review the allergen information for every flavor in your box before ordering.</p></div><div class="actions"><a class="button" href="/builder.php?size=12">Build a box</a><a class="button secondary" href="/#flavors">See all flavors</a></div></div></div></main></body></html>
