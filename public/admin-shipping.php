<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{AdminAuditService,Database,FulfillmentSettingsService};
require_admin_roles(['super_admin','admin']);

$db=Database::connection();$svc=new FulfillmentSettingsService($db);$audit=new AdminAuditService($db);$error='';$notice='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf($_POST['_csrf']??null);
    try{
        $action=(string)($_POST['action']??'');
        if($action==='method'){
            $before=!empty($_POST['id'])?($svc->method((int)$_POST['id'])?:[]):[];
            $id=$svc->saveMethod($_POST);$after=$svc->method($id)?:[];
            $audit->record((int)$_SESSION['admin_id'],'shipping_method_saved','shipping_method',$id,'Fulfillment method saved.',$before,$after);
            $notice='Fulfillment method saved.';
        }elseif($action==='zip'){
            $zip=(string)($_POST['postal_code']??'');$active=!empty($_POST['active']);
            $svc->setPickupZip($zip,$active);
            $audit->record((int)$_SESSION['admin_id'],'pickup_zip_updated','pickup_zip',substr(trim($zip),0,5),$active?'Pickup ZIP enabled.':'Pickup ZIP disabled.',[],['active'=>$active?1:0]);
            $notice='Pickup ZIP updated.';
        }elseif($action==='settings'){
            $before=$svc->settings();$svc->saveSettings($_POST);$after=$svc->settings();
            $audit->record((int)$_SESSION['admin_id'],'fulfillment_settings_updated','fulfillment','global','Pickup and shipping settings updated.',$before,$after);
            $notice='Fulfillment settings saved.';
        }else throw new InvalidArgumentException('Unsupported fulfillment action.');
    }catch(Throwable $e){$error=$e->getMessage();}
}
$methods=$svc->methods();$zips=$svc->pickupZips();$settings=$svc->settings();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Shipping & Pickup · Fudge Donuts Admin</title><link rel="stylesheet" href="<?=htmlspecialchars(asset_url('/assets/app.css'))?>"></head><body class="admin-body">
<header class="admin-topbar"><a class="admin-brand" href="/admin.php">Fudge Donuts <span>Admin</span></a><nav><a href="/admin.php">Dashboard</a><a href="/admin-orders.php">Orders</a><a href="/admin-inventory.php">Inventory</a><a href="/admin-packaging.php">Packaging</a><a class="active" href="/admin-shipping.php">Shipping</a><a href="/admin-promotions.php">Promotions</a><a href="/admin-content.php">Content</a><a href="/admin-reports.php">Reports</a><a href="/admin-operations.php">Operations</a></nav></header>
<main class="admin-shell">
<div class="admin-page-head"><div><p class="eyebrow">Fulfillment</p><h1>Shipping & Local Pickup</h1><p class="admin-welcome">Manage customer-facing delivery rates, pickup eligibility, ETAs and instructions.</p></div></div>
<?php if($error):?><div class="notice error"><?=htmlspecialchars($error)?></div><?php endif;?><?php if($notice):?><div class="notice"><?=htmlspecialchars($notice)?></div><?php endif;?>

<section class="dashboard-panel"><div class="panel-head"><div><p class="eyebrow">Delivery methods</p><h2>Rates & availability</h2></div></div>
<div class="shipping-admin-grid">
<?php foreach($methods as $m):?><form method="post" class="card admin-form shipping-method-card"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="method"><input type="hidden" name="id" value="<?=(int)$m['id']?>">
<h3><?=htmlspecialchars($m['name'])?></h3>
<label>Method name<input name="name" required value="<?=htmlspecialchars($m['name'])?>"></label>
<label>Code<input name="code" pattern="[a-z0-9-]+" required value="<?=htmlspecialchars($m['code'])?>"></label>
<label>Type<select name="type"><option value="shipping" <?=$m['type']==='shipping'?'selected':''?>>Shipping</option><option value="pickup" <?=$m['type']==='pickup'?'selected':''?>>Local pickup</option></select></label>
<label>Description<textarea name="description"><?=htmlspecialchars((string)($m['description']??''))?></textarea></label>
<div class="two"><label>Price (cents)<input type="number" min="0" name="price_cents" value="<?=(int)$m['price_cents']?>"></label><label>Free over (cents)<input type="number" min="0" name="free_over_cents" value="<?=htmlspecialchars((string)($m['free_over_cents']??''))?>"></label></div>
<div class="two"><label>ETA min days<input type="number" min="0" name="eta_min_days" value="<?=htmlspecialchars((string)($m['eta_min_days']??''))?>"></label><label>ETA max days<input type="number" min="0" name="eta_max_days" value="<?=htmlspecialchars((string)($m['eta_max_days']??''))?>"></label></div>
<label>Checkout message<textarea name="checkout_message"><?=htmlspecialchars((string)($m['checkout_message']??''))?></textarea></label>
<div class="two"><label>Sort order<input type="number" name="sort_order" value="<?=(int)$m['sort_order']?>"></label><label class="check"><input type="checkbox" name="active" value="1" <?=(int)$m['active']?'checked':''?>> Active</label></div>
<button class="button secondary">Save method</button></form><?php endforeach;?>
<form method="post" class="card admin-form shipping-method-card"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="method"><h3>Add fulfillment method</h3><label>Name<input name="name" required></label><label>Code<input name="code" pattern="[a-z0-9-]+" required></label><label>Type<select name="type"><option value="shipping">Shipping</option><option value="pickup">Local pickup</option></select></label><label>Description<textarea name="description"></textarea></label><div class="two"><label>Price (cents)<input type="number" min="0" name="price_cents" value="0"></label><label>Free over (cents)<input type="number" min="0" name="free_over_cents"></label></div><div class="two"><label>ETA min days<input type="number" min="0" name="eta_min_days"></label><label>ETA max days<input type="number" min="0" name="eta_max_days"></label></div><label>Checkout message<textarea name="checkout_message"></textarea></label><label>Sort order<input type="number" name="sort_order" value="50"></label><label class="check"><input type="checkbox" name="active" value="1" checked> Active</label><button class="button">Add method</button></form>
</div></section>

<section class="dashboard-grid dashboard-secondary">
<div class="dashboard-panel"><div class="panel-head"><div><p class="eyebrow">Pickup coverage</p><h2>Eligible ZIP codes</h2></div></div>
<form method="post" class="inline-admin"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="zip"><input type="hidden" name="active" value="1"><input name="postal_code" pattern="\d{5}(?:-\d{4})?" required placeholder="85001"><button class="button">Add / enable</button></form>
<div class="pickup-zip-list"><?php foreach($zips as $z):?><form method="post" class="chip"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="zip"><input type="hidden" name="postal_code" value="<?=htmlspecialchars($z['postal_code'])?>"><input type="hidden" name="active" value="<?=(int)$z['active']?0:1?>"><span><?=htmlspecialchars($z['postal_code'])?></span><button class="link"><?=(int)$z['active']?'Disable':'Enable'?></button></form><?php endforeach;?></div></div>

<div class="dashboard-panel"><div class="panel-head"><div><p class="eyebrow">Pickup details</p><h2>Customer instructions</h2></div></div>
<form method="post" class="admin-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="settings">
<label>Pickup location name<input name="pickup_location_name" value="<?=htmlspecialchars($settings['pickup_location_name']??'')?>"></label>
<label>Pickup address<textarea name="pickup_address"><?=htmlspecialchars($settings['pickup_address']??'')?></textarea></label>
<label>Pickup hours<textarea name="pickup_hours"><?=htmlspecialchars($settings['pickup_hours']??'')?></textarea></label>
<label>Pickup instructions<textarea name="pickup_instructions"><?=htmlspecialchars($settings['pickup_instructions']??'')?></textarea></label>
<label>Storewide shipping notice<textarea name="shipping_notice"><?=htmlspecialchars($settings['shipping_notice']??'')?></textarea></label>
<button class="button secondary">Save fulfillment settings</button></form></div>
</section>
</main></body></html>