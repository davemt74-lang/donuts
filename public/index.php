<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use FudgeDonuts\CatalogRepository;
use FudgeDonuts\Database;

$catalog = new CatalogRepository(Database::connection());
$packs = $catalog->packs();
$flavors = $catalog->flavors();
?><!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Fudge Donuts</title><link rel="stylesheet" href="/assets/app.css"></head>
<body>
<header class="nav"><a class="brand" href="/">Fudge Donuts</a><nav><a href="#shop">Shop</a><a href="#flavors">Flavors</a><a href="/builder.php?size=12">Build a Box</a></nav></header>
<main>
<section class="hero">
  <div><p class="eyebrow">Small batch · rich fudge center</p><h1>Not just a donut.<br>A fudge donut.</h1><p>Choose a curated box or build your own mix.</p><a class="button" href="#shop">Shop the boxes</a></div>
  <img src="/images/hero.png" alt="Assorted Fudge Donuts">
</section>
<section id="shop" class="section"><p class="eyebrow">Choose your box</p><h2>Built for sharing. Or not.</h2><div class="grid packs">
<?php foreach ($packs as $pack): ?>
<article class="card"><h3><?=htmlspecialchars($pack['name'])?></h3><p>From <?=money((int)$pack['base_price_cents'])?></p><a class="button secondary" href="/builder.php?size=<?=(int)$pack['size']?>">Build <?=htmlspecialchars($pack['name'])?></a></article>
<?php endforeach; ?>
</div></section>
<section id="flavors" class="section alt"><p class="eyebrow">Current flavors</p><h2>Pick your favorites</h2><div class="grid flavors">
<?php foreach ($flavors as $flavor): ?>
<article class="flavor"><img src="<?=htmlspecialchars($flavor['image_path'] ?: '/images/placeholder.png')?>" alt=""><h3><?=htmlspecialchars($flavor['name'])?></h3><p><?=htmlspecialchars($flavor['description'])?></p><?php if ((int)$flavor['surcharge_cents']): ?><small>+<?=money((int)$flavor['surcharge_cents'])?> each</small><?php endif; ?></article>
<?php endforeach; ?>
</div></section>
<section class="gift"><img src="/images/gift-box.png" alt="Fudge Donuts gift box"><div><p class="eyebrow">Send something better</p><h2>A gift people actually want to open.</h2><p>Gift messaging will be available during checkout.</p></div></section>
</main>
<footer><img src="/images/footer.png" alt=""><p>© <?=date('Y')?> Fudge Donuts</p></footer>
</body></html>
