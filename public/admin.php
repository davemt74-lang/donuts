<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use FudgeDonuts\CatalogRepository;
use FudgeDonuts\Database;

$expected = (string)env('ADMIN_PASSWORD', '');
if (isset($_POST['login'])) {
    verify_csrf($_POST['_csrf'] ?? null);
    if ($expected !== '' && hash_equals($expected, (string)($_POST['password'] ?? ''))) $_SESSION['admin'] = true;
}
if (isset($_POST['logout'])) { verify_csrf($_POST['_csrf'] ?? null); unset($_SESSION['admin']); }
if (empty($_SESSION['admin'])) {
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/app.css"><title>Admin</title></head><body><main class="section narrow"><h1>Store Admin</h1><form method="post"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="password" name="password" required placeholder="Admin password"><button class="button" name="login">Sign in</button></form></main></body></html><?php exit; }

$repo = new CatalogRepository(Database::connection());
$error='';
if (isset($_POST['save_flavor'])) {
    verify_csrf($_POST['_csrf'] ?? null);
    try { $repo->saveFlavor($_POST); header('Location: /admin.php'); exit; } catch (Throwable $e) { $error=$e->getMessage(); }
}
$flavors = $repo->flavors(false);
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/app.css"><title>Admin · Fudge Donuts</title></head><body>
<header class="nav"><a class="brand" href="/admin.php">Fudge Donuts Admin</a><nav><a href="/admin.php">Catalog</a><a href="/admin-orders.php">Orders</a><a href="/admin-inventory.php">Inventory</a><a href="/admin-promotions.php">Promotions</a><a href="/admin-content.php">Content</a><a href="/admin-settings.php">Store Settings</a></nav><form method="post"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><button class="link" name="logout">Logout</button></form></header>
<main class="section"><h1>Flavor Catalog</h1><?php if($error):?><div class="notice"><?=htmlspecialchars($error)?></div><?php endif;?>
<table><thead><tr><th>Flavor</th><th>Surcharge</th><th>Status</th><th>Image</th></tr></thead><tbody>
<?php foreach($flavors as $f):?><tr><td><?=htmlspecialchars($f['name'])?></td><td><?=money((int)$f['surcharge_cents'])?></td><td><?=(int)$f['active']?((int)$f['sold_out']?'Sold out':'Active'):'Inactive'?></td><td><?=htmlspecialchars($f['image_path'])?></td></tr><?php endforeach;?>
</tbody></table>
<h2>Add / update flavor</h2><form method="post" class="admin-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input name="id" type="number" placeholder="ID (blank for new)"><input name="name" required placeholder="Name"><input name="slug" required pattern="[a-z0-9-]+" placeholder="slug"><input name="surcharge_cents" type="number" min="0" value="0" placeholder="Surcharge cents"><input name="image_path" placeholder="/images/flavor-name.png"><textarea name="description" placeholder="Description"></textarea><textarea name="ingredients" placeholder="Ingredients"></textarea><input name="allergens" placeholder="Allergens"><label><input type="checkbox" name="active" value="1" checked> Active</label><label><input type="checkbox" name="sold_out" value="1"> Sold out</label><label><input type="checkbox" name="seasonal" value="1"> Seasonal</label><input name="sort_order" type="number" value="0"><button class="button" name="save_flavor">Save flavor</button></form>
</main></body></html>
