<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{Database,InventoryService};
if(empty($_SESSION['admin'])){header('Location: /admin.php');exit;}
$svc=new InventoryService(Database::connection());$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf($_POST['_csrf']??null);
 try{$svc->setInventory((int)$_POST['flavor_id'],!empty($_POST['track_inventory']),(int)$_POST['stock_on_hand'],(int)$_POST['low_stock_threshold']);header('Location: /admin-inventory.php');exit;}catch(Throwable $e){$error=$e->getMessage();}
}
$rows=$svc->rows();
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/app.css"><title>Inventory · Admin</title></head><body>
<header class="nav"><a class="brand" href="/admin.php">Fudge Donuts Admin</a><nav><a href="/admin.php">Catalog</a><a href="/admin-orders.php">Orders</a><a href="/admin-inventory.php">Inventory</a><a href="/admin-promotions.php">Promotions</a><a href="/admin-settings.php">Store Settings</a></nav></header>
<main class="section"><p class="eyebrow">Production</p><h1>Flavor inventory</h1><?php if($error):?><div class="notice error"><?=htmlspecialchars($error)?></div><?php endif;?>
<table><thead><tr><th>Flavor</th><th>Available</th><th>Reserved</th><th>Tracking</th><th>Update</th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr><td><?=htmlspecialchars($r['name'])?></td><td><?=(int)($r['available']??0)?></td><td><?=(int)($r['reserved']??0)?></td><td><?=!empty($r['track_inventory'])?'On':'Unlimited'?></td><td><form method="post" class="inline-admin"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="flavor_id" value="<?=(int)$r['id']?>"><label><input type="checkbox" name="track_inventory" value="1" <?=!empty($r['track_inventory'])?'checked':''?>> Track</label><input type="number" min="0" name="stock_on_hand" value="<?=(int)($r['stock_on_hand']??0)?>" placeholder="Stock"><input type="number" min="0" name="low_stock_threshold" value="<?=(int)($r['low_stock_threshold']??6)?>" placeholder="Low"><button class="button secondary">Save</button></form></td></tr><?php endforeach;?>
</tbody></table></main></body></html>
