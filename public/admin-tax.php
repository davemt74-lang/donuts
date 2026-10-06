<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{AdminAuditService,Database,TaxService};
require_admin_roles(['super_admin','admin']);

$db=Database::connection();$tax=new TaxService($db);$audit=new AdminAuditService($db);$error='';$notice='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf($_POST['_csrf']??null);
    try{
        $before=$tax->settings();$tax->save($_POST);$after=$tax->settings();
        $audit->record((int)$_SESSION['admin_id'],'tax_settings_updated','tax','global','Tax configuration updated.',$before,$after);
        $notice='Tax settings saved.';
    }catch(Throwable $e){$error=$e->getMessage();}
}
$settings=$tax->settings();$regions=$tax->byRegion($_GET['start']??null,$_GET['end']??null);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Tax · Fudge Donuts Admin</title><link rel="stylesheet" href="/assets/app.css"></head><body class="admin-body">
<header class="admin-topbar"><a class="admin-brand" href="/admin.php">Fudge Donuts <span>Admin</span></a><nav><a href="/admin.php">Dashboard</a><a href="/admin-orders.php">Orders</a><a href="/admin-shipping.php">Shipping</a><a class="active" href="/admin-tax.php">Tax</a><a href="/admin-reports.php">Reports</a><a href="/admin-audit.php">Audit</a></nav></header>
<main class="admin-shell">
<div class="admin-page-head"><div><p class="eyebrow">Compliance</p><h1>Tax Configuration</h1><p class="admin-welcome">Control Stripe Automatic Tax behavior and review collected tax by region.</p></div></div>
<?php if($error):?><div class="notice error"><?=htmlspecialchars($error)?></div><?php endif;?><?php if($notice):?><div class="notice"><?=htmlspecialchars($notice)?></div><?php endif;?>
<section class="dashboard-grid dashboard-secondary">
<div class="dashboard-panel"><h2>Stripe Automatic Tax</h2><form method="post" class="admin-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>">
<label class="check"><input type="checkbox" name="automatic_tax_enabled" value="1" <?=$settings['automatic_tax_enabled']==='1'?'checked':''?>> Enable Stripe Automatic Tax</label>
<label>Stripe product tax code<input name="product_tax_code" value="<?=htmlspecialchars($settings['product_tax_code'])?>" placeholder="txcd_99999999"></label>
<label>Tax behavior<select name="tax_behavior"><option value="exclusive" selected>Exclusive — added at checkout</option></select></label>
<label>Checkout notice<textarea name="checkout_notice"><?=htmlspecialchars($settings['checkout_notice'])?></textarea></label>
<button class="button">Save tax settings</button></form>
<div class="allergen-callout"><strong>Stripe setup required</strong><p>Automatic Tax also requires your business registrations and tax settings to be configured correctly in Stripe. This page controls how the store requests tax calculation; it does not create legal tax registrations.</p></div>
</div>
<div class="dashboard-panel"><h2>Current mode</h2><div class="review-total"><span>Automatic Tax</span><strong><?=$settings['automatic_tax_enabled']==='1'?'Enabled':'Disabled'?></strong></div><div class="review-total"><span>Tax behavior</span><strong><?=htmlspecialchars(ucfirst($settings['tax_behavior']))?></strong></div><div class="review-total"><span>Product tax code</span><strong><?=htmlspecialchars($settings['product_tax_code']?:'Stripe default')?></strong></div></div>
</section>
<section class="dashboard-panel"><div class="panel-head"><div><p class="eyebrow">Collected tax</p><h2>By region</h2></div><form method="get" class="inline-admin"><input type="date" name="start" value="<?=htmlspecialchars((string)($_GET['start']??''))?>"><input type="date" name="end" value="<?=htmlspecialchars((string)($_GET['end']??''))?>"><button class="button secondary">Filter</button></form></div>
<div class="dashboard-table-wrap"><table class="dashboard-table"><thead><tr><th>Region</th><th>Orders</th><th>Gross</th><th>Tax collected</th></tr></thead><tbody><?php if(!$regions):?><tr><td colspan="4" class="empty-cell">No tax data yet.</td></tr><?php endif;?><?php foreach($regions as $row):?><tr><td><strong><?=htmlspecialchars($row['region']?:'Unknown')?></strong></td><td><?=(int)$row['orders']?></td><td><?=money((int)$row['gross_cents'])?></td><td><?=money((int)$row['tax_cents'])?></td></tr><?php endforeach;?></tbody></table></div></section>
</main></body></html>