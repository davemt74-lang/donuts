<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{CatalogRepository,Database,PresetPackService,SeoService};

$catalog=new CatalogRepository(Database::connection());
$slug=(string)($_GET['slug']??'');
try{$box=(new PresetPackService($catalog))->buildBySlug($slug);}catch(Throwable){http_response_code(404);exit('Pack not found');}
$seo=new SeoService($catalog,(string)env('APP_URL','https://example.com'));$schema=json_encode($seo->presetSchema($box),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$canonical=$seo->canonical('/preset.php?slug='.rawurlencode($slug));
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=htmlspecialchars($box['name'])?> · Fudge Donuts</title><meta name="description" content="Curated <?=htmlspecialchars((string)$box['size'])?> pack from Fudge Donuts."><link rel="canonical" href="<?=htmlspecialchars($canonical)?>"><meta property="og:title" content="<?=htmlspecialchars($box['name'])?> · Fudge Donuts"><meta property="og:type" content="product"><meta property="og:url" content="<?=htmlspecialchars($canonical)?>"><?php if($box['image_path']):?><meta property="og:image" content="<?=htmlspecialchars($seo->canonical($box['image_path']))?>"><?php endif;?><script type="application/ld+json"><?=$schema?></script><link rel="stylesheet" href="<?=htmlspecialchars(asset_url('/assets/app.css'))?>"></head><body>
<a class="skip-link" href="#main-content">Skip to main content</a>
<header class="nav"><a class="brand" href="/">Fudge Donuts</a><nav><a href="/">Shop</a><a href="/cart.php">Cart</a></nav></header>
<main id="main-content" class="section narrow"><p class="eyebrow">Curated assortment</p><h1><?=htmlspecialchars($box['name'])?></h1>
<?php if($box['image_path']):?><img class="preset-hero" src="<?=htmlspecialchars($box['image_path'])?>" alt="<?=htmlspecialchars($box['name'])?>" decoding="async" fetchpriority="high"><?php endif;?>
<div class="review-list"><?php foreach($box['items'] as $item):?><div class="review-line"><img src="<?=htmlspecialchars($item['image_path'])?>" alt="<?=htmlspecialchars($item['name'])?>" loading="lazy" decoding="async"><div><strong><?=htmlspecialchars($item['name'])?></strong><small>Quantity <?=(int)$item['quantity']?></small></div><span><?=(int)$item['line_surcharge_cents']>0?'+'.money((int)$item['line_surcharge_cents']):'Included'?></span></div><?php endforeach;?></div>
<div class="review-total"><span>Total</span><strong><?=money((int)$box['total_cents'])?></strong></div>
<form method="post" action="/cart.php"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="add_preset"><input type="hidden" name="slug" value="<?=htmlspecialchars($box['preset_slug'])?>"><button class="button">Add to cart</button></form>
</main></body></html>
