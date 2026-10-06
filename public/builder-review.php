<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

$box = $_SESSION['pending_box'] ?? null;
if (!is_array($box)) { header('Location: /'); exit; }
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Review Your Box · Fudge Donuts</title><link rel="stylesheet" href="/assets/app.css"></head><body>
<header class="nav"><a class="brand" href="/">Fudge Donuts</a></header>
<main class="section narrow"><p class="eyebrow">Your custom box</p><h1>Review your <?=$box['size']?> pack</h1>
<div class="review-list"><?php foreach($box['items'] as $item):?><div class="review-line"><img src="<?=htmlspecialchars($item['image_path'])?>" alt=""><div><strong><?=htmlspecialchars($item['name'])?></strong><small>Quantity <?=$item['quantity']?></small></div><span><?=(int)$item['line_surcharge_cents']>0?'+'.money((int)$item['line_surcharge_cents']):'Included'?></span></div><?php endforeach;?></div>
<div class="review-total"><span>Box total</span><strong><?=money((int)$box['total_cents'])?></strong></div>
<div class="actions"><a class="button secondary" href="/builder.php?size=<?=$box['size']?>">Edit box</a><form method="post" action="/cart.php"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="add_pending"><button class="button" type="submit">Add to cart</button></form></div>
</main></body></html>
