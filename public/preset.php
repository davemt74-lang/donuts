<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{CatalogRepository,Database,PresetPackService};

$catalog=new CatalogRepository(Database::connection());
$slug=(string)($_GET['slug']??'');
try{$box=(new PresetPackService($catalog))->buildBySlug($slug);}catch(Throwable){http_response_code(404);exit('Pack not found');}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=htmlspecialchars($box['name'])?> · Fudge Donuts</title><link rel="stylesheet" href="/assets/app.css"></head><body>
<header class="nav"><a class="brand" href="/">Fudge Donuts</a><nav><a href="/">Shop</a><a href="/cart.php">Cart</a></nav></header>
<main class="section narrow"><p class="eyebrow">Curated assortment</p><h1><?=htmlspecialchars($box['name'])?></h1>
<?php if($box['image_path']):?><img class="preset-hero" src="<?=htmlspecialchars($box['image_path'])?>" alt=""><?php endif;?>
<div class="review-list"><?php foreach($box['items'] as $item):?><div class="review-line"><img src="<?=htmlspecialchars($item['image_path'])?>" alt=""><div><strong><?=htmlspecialchars($item['name'])?></strong><small>Quantity <?=(int)$item['quantity']?></small></div><span><?=(int)$item['line_surcharge_cents']>0?'+'.money((int)$item['line_surcharge_cents']):'Included'?></span></div><?php endforeach;?></div>
<div class="review-total"><span>Total</span><strong><?=money((int)$box['total_cents'])?></strong></div>
<form method="post" action="/cart.php"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="add_preset"><input type="hidden" name="slug" value="<?=htmlspecialchars($box['preset_slug'])?>"><button class="button">Add to cart</button></form>
</main></body></html>
