<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use FudgeDonuts\CatalogRepository;
use FudgeDonuts\Database;
use FudgeDonuts\PackBuilderService;

$size = (int)($_GET['size'] ?? $_POST['size'] ?? 12);
$catalog = new CatalogRepository(Database::connection());
$pack = $catalog->packBySize($size);
if (!$pack || !(int)$pack['customizable']) { http_response_code(404); exit('Pack not found'); }

$builder = new PackBuilderService($catalog);
$eligible = array_flip($catalog->eligibleFlavorIds((int)$pack['id']));
$flavors = array_values(array_filter($catalog->flavors(), fn($f) => isset($eligible[(int)$f['id']])));
$draftKey = 'builder_' . $size;
$draft = $_SESSION[$draftKey] ?? [];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf($_POST['_csrf'] ?? null);
    $draft = $builder->normalizeDraft($size, is_array($_POST['flavor'] ?? null) ? $_POST['flavor'] : []);
    $_SESSION[$draftKey] = $draft;
    try {
        $box = $builder->build($size, $draft);
        $_SESSION['pending_box'] = $box;
        header('Location: /builder-review.php');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Build a <?=$size?> Pack · Fudge Donuts</title><link rel="stylesheet" href="/assets/app.css"></head><body>
<header class="nav"><a class="brand" href="/">Fudge Donuts</a><nav><a href="/">Home</a><a href="/builder.php?size=3">3 Pack</a><a href="/builder.php?size=6">6 Pack</a><a href="/builder.php?size=12">12 Pack</a></nav></header>
<main class="section builder">
<p class="eyebrow">Build your own</p><h1><?=$size?> Pack</h1>
<p>Choose exactly <?=$size?> donuts. Premium flavors update your box price automatically.</p><div class="allergen-callout compact"><strong>Food allergy notice</strong><span>Open any flavor for ingredient and allergen details. Products may be prepared in a shared kitchen.</span></div>
<?php if ($error): ?><div class="notice error"><?=htmlspecialchars($error)?></div><?php endif; ?>
<form method="post" id="pack-form" data-size="<?=$size?>" data-base-price="<?=(int)$pack['base_price_cents']?>">
<input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="size" value="<?=$size?>">
<div class="grid flavors builder-grid">
<?php foreach ($flavors as $f): $qty=(int)($draft[(int)$f['id']]??0); ?>
<article class="flavor builder-card" data-surcharge="<?=(int)$f['surcharge_cents']?>">
<img src="<?=htmlspecialchars($f['image_path'])?>" alt="<?=htmlspecialchars($f['name'])?>">
<div class="builder-copy"><h3><a href="/flavor.php?slug=<?=urlencode($f['slug'])?>" target="_blank" rel="noopener"><?=htmlspecialchars($f['name'])?></a></h3><p><?=htmlspecialchars($f['description'])?></p><?php if(trim((string)$f['allergens'])!==''):?><small class="allergen-summary">Allergens: <?=htmlspecialchars($f['allergens'])?></small><?php endif;?>
<?php if ((int)$f['surcharge_cents']): ?><small>+<?=money((int)$f['surcharge_cents'])?> each</small><?php else: ?><small>Included</small><?php endif; ?></div>
<div class="stepper"><button type="button" class="step minus" aria-label="Remove one <?=htmlspecialchars($f['name'])?>">−</button><input class="qty" readonly inputmode="numeric" value="<?=$qty?>" name="flavor[<?=(int)$f['id']?>]" aria-label="<?=htmlspecialchars($f['name'])?> quantity"><button type="button" class="step plus" aria-label="Add one <?=htmlspecialchars($f['name'])?>">+</button></div>
</article>
<?php endforeach; ?>
</div>
<div class="builder-bar"><div><strong><span id="count">0</span> / <?=$size?> selected</strong><div id="builder-status" class="muted">Choose <?=$size?> donuts</div></div><div class="builder-total"><span>Box total</span><strong id="total"><?=money((int)$pack['base_price_cents'])?></strong></div><button class="button" id="continue" type="submit" disabled>Review box</button></div>
</form></main><script src="/assets/builder.js"></script></body></html>
