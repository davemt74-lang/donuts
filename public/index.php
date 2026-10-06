<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use FudgeDonuts\CatalogRepository;
use FudgeDonuts\ContentService;
use FudgeDonuts\Database;

$db = Database::connection();
$catalog = new CatalogRepository($db);
$content = new ContentService($db);
$packs = $catalog->packs();
$presets = $catalog->presets();
$flavors = $catalog->flavors();
?><!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=htmlspecialchars($content->get('seo_title','Fudge Donuts'))?></title><meta name="description" content="<?=htmlspecialchars($content->get('seo_description'))?>"><link rel="stylesheet" href="/assets/app.css"></head>
<body>
<header class="nav"><a class="brand" href="/">Fudge Donuts</a><nav><a href="#shop">Shop</a><a href="#flavors">Flavors</a><a href="/builder.php?size=12">Build a Box</a><a href="/account.php">Account</a><a href="/cart.php">Cart</a></nav></header>
<main>
<section class="hero">
  <div><p class="eyebrow">Small batch · rich fudge center</p><h1><?=htmlspecialchars($content->get('hero_title'))?></h1><p><?=htmlspecialchars($content->get('hero_subtitle'))?></p><a class="button" href="#shop">Shop the boxes</a></div>
  <img src="/images/hero.png" alt="Assorted Fudge Donuts">
</section>
<section id="shop" class="section"><p class="eyebrow">Choose your box</p><h2>Curated or completely yours.</h2>
<div class="grid packs"><?php foreach($presets as $preset):?><article class="card preset-card"><?php if($preset['image_path']):?><img src="<?=htmlspecialchars($preset['image_path'])?>" alt=""><?php endif;?><p class="eyebrow">Curated <?=(int)$preset['size']?> pack</p><h3><?=htmlspecialchars($preset['name'])?></h3><p><?=htmlspecialchars($preset['description'])?></p><p>From <?=money((int)$preset['base_price_cents'])?></p><a class="button secondary" href="/preset.php?slug=<?=urlencode($preset['slug'])?>">Choose this box</a></article><?php endforeach;?></div>
<h2 class="subsection-title">Build your own</h2><div class="grid packs">
<?php foreach ($packs as $pack): ?>
<article class="card"><p class="eyebrow">Custom box</p><h3><?=htmlspecialchars($pack['name'])?></h3><p>Start at <?=money((int)$pack['base_price_cents'])?>, then choose every flavor.</p><a class="button secondary" href="/builder.php?size=<?=(int)$pack['size']?>">Build <?=htmlspecialchars($pack['name'])?></a></article>
<?php endforeach; ?>
</div></section>
<section id="flavors" class="section alt"><p class="eyebrow">Current flavors</p><h2>Pick your favorites</h2><div class="grid flavors">
<?php foreach ($flavors as $flavor): ?>
<article class="flavor"><a class="flavor-image-link" href="/flavor.php?slug=<?=urlencode($flavor['slug'])?>"><img src="<?=htmlspecialchars($flavor['image_path'] ?: '/images/placeholder.png')?>" alt="<?=htmlspecialchars($flavor['name'])?>"></a><h3><a href="/flavor.php?slug=<?=urlencode($flavor['slug'])?>"><?=htmlspecialchars($flavor['name'])?></a></h3><p><?=htmlspecialchars($flavor['description'])?></p><?php if ((int)$flavor['surcharge_cents']): ?><small>+<?=money((int)$flavor['surcharge_cents'])?> each</small><?php endif; ?><?php if(trim((string)$flavor['allergens'])!==''):?><small class="allergen-summary">Allergens: <?=htmlspecialchars($flavor['allergens'])?></small><?php endif;?></article>
<?php endforeach; ?>
</div></section>
<section class="gift"><img src="/images/gift-box.png" alt="Fudge Donuts gift box"><div><p class="eyebrow">Send something better</p><h2>A gift people actually want to open.</h2><p>Gift messaging will be available during checkout.</p></div></section>
<section class="section newsletter"><p class="eyebrow">Stay in the loop</p><h2>Fresh drops, seasonal flavors & gifts.</h2><?php if(!empty($_SESSION['flash'])):?><div class="notice"><?=htmlspecialchars((string)$_SESSION['flash'])?></div><?php unset($_SESSION['flash']);endif;?><form method="post" action="/newsletter.php" class="newsletter-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="email" name="email" required placeholder="Email address"><button class="button">Join the list</button></form><p><a href="/story.php">Our Story</a> · <a href="/faq.php">FAQ</a></p></section></main>
<footer><img src="/images/footer.png" alt=""><div class="footer-meta"><p>© <?=date('Y')?> Fudge Donuts</p><nav><a href="/policy.php?type=terms">Terms</a><a href="/policy.php?type=privacy">Privacy</a><a href="/policy.php?type=refunds">Refunds</a><a href="/policy.php?type=shipping">Shipping & Pickup</a></nav></div></footer>
</body></html>
