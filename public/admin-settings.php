<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{AdminService,Database};
if(empty($_SESSION['admin'])){header('Location: /admin.php');exit;}
$admin=new AdminService(Database::connection());$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf($_POST['_csrf']??null);
 try{$admin->setPickupZip((string)($_POST['postal_code']??''),($_POST['action']??'add')==='add');header('Location: /admin-settings.php');exit;}catch(Throwable $e){$error=$e->getMessage();}
}
$zips=$admin->pickupZips();$discounts=$admin->discounts();
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/app.css"><title>Store Settings · Admin</title></head><body>
<header class="nav"><a class="brand" href="/admin.php">Fudge Donuts Admin</a><nav><a href="/admin.php">Catalog</a><a href="/admin-orders.php">Orders</a><a href="/admin-inventory.php">Inventory</a><a href="/admin-promotions.php">Promotions</a><a href="/admin-content.php">Content</a><a href="/admin-reports.php">Reports</a><a href="/admin-settings.php">Store Settings</a></nav></header>
<main class="section"><p class="eyebrow">Store settings</p><h1>Pickup & discounts</h1><?php if($error):?><div class="notice error"><?=htmlspecialchars($error)?></div><?php endif;?>
<section class="admin-panel"><h2>Local pickup ZIP codes</h2><form method="post" class="inline-admin"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input name="postal_code" pattern="\d{5}" required placeholder="85001"><input type="hidden" name="action" value="add"><button class="button">Add ZIP</button></form><div class="chip-list"><?php foreach($zips as $z):?><form method="post" class="chip"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="postal_code" value="<?=htmlspecialchars($z['postal_code'])?>"><input type="hidden" name="action" value="remove"><span><?=htmlspecialchars($z['postal_code'])?></span><?php if((int)$z['active']):?><button aria-label="Disable ZIP">×</button><?php else:?><small>disabled</small><?php endif;?></form><?php endforeach;?></div></section>
<section class="admin-panel"><h2>Discount rules</h2><table><thead><tr><th>Name</th><th>Code</th><th>Type</th><th>Value</th><th>Min boxes</th><th>Status</th></tr></thead><tbody><?php foreach($discounts as $d):?><tr><td><?=htmlspecialchars($d['name'])?></td><td><?=htmlspecialchars($d['code']??'Automatic')?></td><td><?=htmlspecialchars($d['type'])?></td><td><?=$d['type']==='percent'?(int)$d['value'].'%':money((int)$d['value'])?></td><td><?=(int)$d['min_units']?></td><td><?=(int)$d['active']?'Active':'Inactive'?></td></tr><?php endforeach;?></tbody></table></section>
</main></body></html>
