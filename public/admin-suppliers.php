<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{AdminAuditService,Database,SupplierPurchasingService};
require_admin_roles(['super_admin','admin']);

$db=Database::connection();$svc=new SupplierPurchasingService($db);$audit=new AdminAuditService($db);$error='';$notice='';
$id=(int)($_GET['id']??$_POST['supplier_id']??0);
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf($_POST['_csrf']??null);
 try{
  $action=(string)($_POST['action']??'');
  if($action==='create_supplier'){
    $id=$svc->createSupplier($_POST,(int)$_SESSION['admin_id']);
    $audit->record((int)$_SESSION['admin_id'],'supplier_created','supplier',$id,'Supplier created.',[],['name'=>(string)($_POST['name']??'')]);
    $notice='Supplier created.';
  }elseif($action==='toggle_supplier'){
    $active=!empty($_POST['active']);$svc->setSupplierActive($id,$active);
    $audit->record((int)$_SESSION['admin_id'],'supplier_status_changed','supplier',$id,$active?'Supplier activated.':'Supplier deactivated.');
    $notice=$active?'Supplier activated.':'Supplier deactivated.';
  }elseif($action==='create_item'){
    $item=$svc->createItem($id,$_POST);
    $audit->record((int)$_SESSION['admin_id'],'supplier_item_created','supplier_item',$item,'Supplier ingredient item created.',[],['supplier_id'=>$id,'ingredient_name'=>(string)($_POST['ingredient_name']??'')]);
    $notice='Supplier item created.';
  }else throw new InvalidArgumentException('Unsupported supplier action.');
 }catch(Throwable $e){$error=$e->getMessage();}
}
$suppliers=$svc->suppliers();$current=$id?$svc->supplier($id):null;$summary=$svc->summary();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Suppliers · Fudge Donuts Admin</title><link rel="stylesheet" href="<?=htmlspecialchars(asset_url('/assets/app.css'))?>"></head><body class="admin-body">
<a class="skip-link" href="#admin-main">Skip to admin content</a>
<header class="admin-topbar"><a class="admin-brand" href="/admin.php">Fudge Donuts <span>Admin</span></a><nav><a href="/admin.php">Dashboard</a><a class="active" href="/admin-suppliers.php">Suppliers</a><a href="/admin-purchase-orders.php">Purchase Orders</a><a href="/admin-ingredient-lots.php">Ingredients</a><a href="/admin-recipes.php">Recipes</a><a href="/admin-batches.php">Batches</a></nav></header>
<main id="admin-main" tabindex="-1" class="admin-shell">
<div class="admin-page-head"><div><p class="eyebrow">Procurement</p><h1>Suppliers</h1><p class="admin-welcome">Manage approved vendors and canonical ingredient purchasing items.</p></div></div>
<?php if($error):?><div class="notice error" role="alert"><?=htmlspecialchars($error)?></div><?php endif;?><?php if($notice):?><div class="notice" role="status"><?=htmlspecialchars($notice)?></div><?php endif;?>
<section class="dashboard-kpis"><article class="kpi-card"><span>Active suppliers</span><strong><?=$summary['active_suppliers']?></strong></article><article class="kpi-card"><span>Open POs</span><strong><?=$summary['open_po']?></strong></article><article class="kpi-card <?=$summary['overdue_po']?'kpi-alert':''?>"><span>Overdue POs</span><strong><?=$summary['overdue_po']?></strong></article><article class="kpi-card"><span>Open value</span><strong><?=money((int)$summary['open_value_cents'])?></strong></article></section>

<section class="dashboard-grid dashboard-secondary"><div class="dashboard-panel"><h2>Supplier directory</h2><div class="dashboard-table-wrap"><table class="dashboard-table"><thead><tr><th>Supplier</th><th>Contact</th><th>Status</th><th>Items</th></tr></thead><tbody><?php if(!$suppliers):?><tr><td colspan="4" class="empty-cell">No suppliers yet.</td></tr><?php endif;?><?php foreach($suppliers as $s):?><tr><td><a href="/admin-suppliers.php?id=<?=(int)$s['id']?>"><strong><?=htmlspecialchars($s['name'])?></strong></a></td><td><?=htmlspecialchars($s['contact_name'])?><small><?=htmlspecialchars($s['email'])?></small></td><td><?=((int)$s['active']?'Active':'Inactive')?></td><td><?=(int)$s['active_items']?></td></tr><?php endforeach;?></tbody></table></div></div>
<div class="dashboard-panel"><h2>Add supplier</h2><form method="post" class="admin-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="create_supplier"><label>Name<input name="name" required maxlength="190"></label><label>Contact name<input name="contact_name" maxlength="190"></label><label>Email<input type="email" name="email" maxlength="190"></label><label>Phone<input name="phone" maxlength="64"></label><label>Address<textarea name="address"></textarea></label><label>Notes<textarea name="notes"></textarea></label><label class="check"><input type="checkbox" name="active" value="1" checked> Active supplier</label><button class="button">Add supplier</button></form></div></section>

<?php if($current):?><section class="dashboard-panel"><div class="panel-head"><div><p class="eyebrow">Supplier</p><h2><?=htmlspecialchars($current['name'])?></h2></div><form method="post"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="toggle_supplier"><input type="hidden" name="supplier_id" value="<?=$id?>"><input type="hidden" name="active" value="<?=(int)$current['active']?0:1?>"><button class="button secondary"><?=(int)$current['active']?'Deactivate':'Activate'?></button></form></div>
<div class="supplier-detail-grid"><div><p><?=nl2br(htmlspecialchars($current['address']))?></p><p><?=htmlspecialchars($current['contact_name'])?><br><?=htmlspecialchars($current['email'])?><br><?=htmlspecialchars($current['phone'])?></p></div><div><p><?=nl2br(htmlspecialchars($current['notes']))?></p></div></div>
<h3>Purchasing items</h3><div class="dashboard-table-wrap"><table class="dashboard-table"><thead><tr><th>Ingredient</th><th>SKU</th><th>Unit</th><th>Cost / unit</th><th>Lead time</th><th>Minimum</th></tr></thead><tbody><?php if(!$current['items']):?><tr><td colspan="6" class="empty-cell">No purchasing items yet.</td></tr><?php endif;?><?php foreach($current['items'] as $item):?><tr><td><strong><?=htmlspecialchars($item['ingredient_name'])?></strong></td><td><?=htmlspecialchars($item['supplier_sku'])?></td><td><?=htmlspecialchars($item['quantity_unit'])?></td><td><?=money((int)$item['unit_cost_cents'])?></td><td><?=(int)$item['lead_time_days']?> days</td><td><?=htmlspecialchars((string)($item['min_order_quantity']??''))?></td></tr><?php endforeach;?></tbody></table></div>
<?php if((int)$current['active']):?><h3>Add supplier item</h3><form method="post" class="inline-admin supplier-item-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="create_item"><input type="hidden" name="supplier_id" value="<?=$id?>"><label>Ingredient<input name="ingredient_name" required maxlength="190"></label><label>Supplier SKU<input name="supplier_sku" maxlength="120"></label><label>Unit<input name="quantity_unit" required maxlength="32" placeholder="oz"></label><label>Cost / unit (cents)<input type="number" name="unit_cost_cents" min="0" value="0"></label><label>Lead time days<input type="number" name="lead_time_days" min="0" value="0"></label><label>Minimum order qty<input type="number" name="min_order_quantity" step="0.001" min="0.001"></label><button class="button">Add item</button></form><?php endif;?>
</section><?php endif;?>
</main></body></html>