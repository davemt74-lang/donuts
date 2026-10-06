<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{CatalogRepository,Database};
require_admin_roles(['super_admin','admin']);

$db=Database::connection();$repo=new CatalogRepository($db);$error='';$notice='';
if(isset($_POST['save_flavor'])){
    verify_csrf($_POST['_csrf']??null);
    try{$repo->saveFlavor($_POST);$notice='Flavor saved.';}catch(Throwable $e){$error=$e->getMessage();}
}
$flavors=$repo->flavors(false);
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/app.css"><title>Flavors · Fudge Donuts Admin</title></head><body class="admin-body">
<header class="admin-topbar"><a class="admin-brand" href="/admin.php">Fudge Donuts <span>Admin</span></a><nav><a href="/admin.php">Dashboard</a><a class="active" href="/admin-flavors.php">Flavors</a><a href="/admin-packs.php">Packs</a><a href="/admin-orders.php">Orders</a><a href="/admin-inventory.php">Inventory</a><a href="/admin-promotions.php">Promotions</a><a href="/admin-content.php">Content</a><a href="/admin-reports.php">Reports</a><a href="/admin-notifications.php">Email</a><a href="/admin-marketing.php">Marketing</a></nav></header>
<main class="admin-shell"><div class="admin-page-head"><div><p class="eyebrow">Catalog</p><h1>Flavors</h1></div><a class="button secondary" href="/admin-packs.php">Manage packs</a></div>
<?php if($error):?><div class="notice error"><?=htmlspecialchars($error)?></div><?php endif;?><?php if($notice):?><div class="notice"><?=htmlspecialchars($notice)?></div><?php endif;?>
<div class="admin-table-wrap"><table><thead><tr><th>Flavor</th><th>Surcharge</th><th>Status</th><th>Image</th></tr></thead><tbody><?php foreach($flavors as $f):?><tr><td><strong><?=htmlspecialchars($f['name'])?></strong><br><small><?=htmlspecialchars($f['slug'])?></small></td><td><?=money((int)$f['surcharge_cents'])?></td><td><span class="status"><?=(int)$f['active']?((int)$f['sold_out']?'Sold out':'Active'):'Inactive'?></span></td><td><?=htmlspecialchars($f['image_path'])?></td></tr><?php endforeach;?></tbody></table></div>
<section class="admin-panel"><h2>Add / update flavor</h2><form method="post" class="admin-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input name="id" type="number" placeholder="ID (blank for new)"><input name="name" required placeholder="Name"><input name="slug" required pattern="[a-z0-9-]+" placeholder="slug"><input name="surcharge_cents" type="number" min="0" value="0" placeholder="Surcharge cents"><input name="image_path" placeholder="/images/flavor-name.png"><textarea name="description" placeholder="Description"></textarea><textarea name="ingredients" placeholder="Ingredients"></textarea><input name="allergens" placeholder="Allergens"><label><input type="checkbox" name="active" value="1" checked> Active</label><label><input type="checkbox" name="sold_out" value="1"> Sold out</label><label><input type="checkbox" name="seasonal" value="1"> Seasonal</label><input name="sort_order" type="number" value="0"><button class="button" name="save_flavor">Save flavor</button></form></section>
</main></body></html>
