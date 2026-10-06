<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use FudgeDonuts\CatalogRepository;
use FudgeDonuts\Database;
use FudgeDonuts\PricingService;

$size = (int)($_GET['size'] ?? $_POST['size'] ?? 12);
$catalog = new CatalogRepository(Database::connection());
$pack = $catalog->packBySize($size);
if (!$pack) { http_response_code(404); exit('Pack not found'); }
$eligible = array_flip($catalog->eligibleFlavorIds((int)$pack['id']));
$flavors = array_values(array_filter($catalog->flavors(), fn($f) => isset($eligible[(int)$f['id']])));
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf($_POST['_csrf'] ?? null);
    try {
        $pricing = (new PricingService($catalog))->customPackTotal($size, $_POST['flavor'] ?? []);
        $message = 'Valid box total: ' . money($pricing['total_cents']);
    } catch (Throwable $e) { $message = $e->getMessage(); }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Build a <?=$size?> Pack · Fudge Donuts</title><link rel="stylesheet" href="/assets/app.css"></head><body>
<header class="nav"><a class="brand" href="/">Fudge Donuts</a><nav><a href="/">Home</a></nav></header>
<main class="section builder"><p class="eyebrow">Build your own</p><h1><?=$size?> Pack</h1><p>Choose exactly <?=$size?> donuts. Premium flavors may add a small surcharge.</p>
<?php if ($message): ?><div class="notice"><?=htmlspecialchars($message)?></div><?php endif; ?>
<form method="post" id="pack-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="size" value="<?=$size?>">
<div class="grid flavors">
<?php foreach ($flavors as $f): ?><article class="flavor"><img src="<?=htmlspecialchars($f['image_path'])?>" alt=""><h3><?=htmlspecialchars($f['name'])?></h3><p><?=htmlspecialchars($f['description'])?></p><label>Qty <input class="qty" type="number" min="0" max="<?=$size?>" value="0" name="flavor[<?=(int)$f['id']?>]"></label></article><?php endforeach; ?>
</div><div class="builder-bar"><strong><span id="count">0</span> / <?=$size?> selected</strong><button class="button" type="submit">Validate box</button></div></form></main>
<script>const max=<?=$size?>,inputs=[...document.querySelectorAll('.qty')],count=document.querySelector('#count');function update(){count.textContent=inputs.reduce((n,i)=>n+(parseInt(i.value)||0),0)}inputs.forEach(i=>i.addEventListener('input',update));update();</script>
</body></html>
