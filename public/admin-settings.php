<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{AdminAuditService,Database,StoreSettingsService};
require_admin_roles(['super_admin','admin']);

$db=Database::connection();$svc=new StoreSettingsService($db);$audit=new AdminAuditService($db);$error='';$notice='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf($_POST['_csrf']??null);
    try{
        $before=$svc->all();$svc->save($_POST);$after=$svc->all();
        $audit->record((int)$_SESSION['admin_id'],'store_settings_updated','store_settings','global','Store identity and launch settings updated.',$before,$after);
        $notice='Store settings saved.';
    }catch(Throwable $e){$error=$e->getMessage();}
}
$s=$svc->all();
$timezones=['UTC','America/Phoenix','America/Los_Angeles','America/Denver','America/Chicago','America/New_York'];
if(!in_array($s['timezone'],$timezones,true))$timezones[]=$s['timezone'];
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Settings · Fudge Donuts Admin</title><link rel="stylesheet" href="<?=htmlspecialchars(asset_url('/assets/app.css'))?>"></head><body class="admin-body">
<a class="skip-link" href="#admin-main">Skip to admin content</a>
<header class="admin-topbar"><a class="admin-brand" href="/admin.php"><?=htmlspecialchars($s['store_name'])?> <span>Admin</span></a><nav><a href="/admin.php">Dashboard</a><a href="/admin-orders.php">Orders</a><a href="/admin-shipping.php">Shipping</a><a href="/admin-tax.php">Tax</a><a href="/admin-content.php">Content</a><a class="active" href="/admin-settings.php">Settings</a><a href="/admin-operations.php">Operations</a></nav></header>
<main id="admin-main" tabindex="-1" class="admin-shell">
<div class="admin-page-head"><div><p class="eyebrow">Business configuration</p><h1>Store Settings</h1><p class="admin-welcome">Canonical business identity used by orders, support, SEO and transactional communications.</p></div></div>
<?php if($error):?><div class="notice error" role="alert"><?=htmlspecialchars($error)?></div><?php endif;?><?php if($notice):?><div class="notice" role="status"><?=htmlspecialchars($notice)?></div><?php endif;?>
<form method="post" class="settings-sections"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>">
<section class="dashboard-panel"><div class="panel-head"><div><p class="eyebrow">Identity</p><h2>Store & business</h2></div></div><div class="admin-form">
<label>Store name<input name="store_name" required maxlength="120" value="<?=htmlspecialchars($s['store_name'])?>"></label>
<label>Legal business name<input name="legal_name" required maxlength="190" value="<?=htmlspecialchars($s['legal_name'])?>"></label>
<div class="two"><label>Contact email<input type="email" name="contact_email" required value="<?=htmlspecialchars($s['contact_email'])?>"></label><label>Support email<input type="email" name="support_email" value="<?=htmlspecialchars($s['support_email'])?>" placeholder="Optional — falls back to contact email"></label></div>
<label>Phone<input type="tel" name="phone" maxlength="40" value="<?=htmlspecialchars($s['phone'])?>"></label>
</div></section>

<section class="dashboard-panel"><div class="panel-head"><div><p class="eyebrow">Location</p><h2>Business address</h2></div></div><div class="admin-form">
<label>Address line 1<input name="address_line1" value="<?=htmlspecialchars($s['address_line1'])?>"></label>
<label>Address line 2<input name="address_line2" value="<?=htmlspecialchars($s['address_line2'])?>"></label>
<div class="three"><label>City<input name="city" value="<?=htmlspecialchars($s['city'])?>"></label><label>State / region<input name="region" value="<?=htmlspecialchars($s['region'])?>"></label><label>Postal code<input name="postal_code" maxlength="20" value="<?=htmlspecialchars($s['postal_code'])?>"></label></div>
<div class="two"><label>Country code<input name="country" maxlength="2" value="<?=htmlspecialchars($s['country'])?>"></label><label>Timezone<select name="timezone"><?php foreach($timezones as $tz):?><option value="<?=htmlspecialchars($tz)?>" <?=$s['timezone']===$tz?'selected':''?>><?=htmlspecialchars($tz)?></option><?php endforeach;?></select></label></div>
</div></section>

<section class="dashboard-panel"><div class="panel-head"><div><p class="eyebrow">Operations</p><h2>Orders & social presence</h2></div></div><div class="admin-form">
<label>Order prefix<input name="order_prefix" required pattern="[A-Za-z0-9]{2,8}" maxlength="8" value="<?=htmlspecialchars($s['order_prefix'])?>"><small>Used only for new orders. Existing order numbers do not change.</small></label>
<label>Instagram URL<input type="url" name="instagram_url" value="<?=htmlspecialchars($s['instagram_url'])?>" placeholder="https://…"></label>
<label>Facebook URL<input type="url" name="facebook_url" value="<?=htmlspecialchars($s['facebook_url'])?>" placeholder="https://…"></label>
<div class="allergen-callout compact"><strong>Infrastructure stays in .env</strong><span>Stripe keys, database credentials, SMTP credentials, APP_URL and APP_KEY are deployment secrets/configuration and are intentionally not editable here.</span></div>
<button class="button">Save store settings</button></div></section>
</form></main></body></html>