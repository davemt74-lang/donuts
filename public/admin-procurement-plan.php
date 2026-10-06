<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{AdminAuditService,Database,IngredientProcurementPlanningService};
require_admin_roles(['super_admin','admin']);

$db=Database::connection();$svc=new IngredientProcurementPlanningService($db);$audit=new AdminAuditService($db);$error='';$notice='';
$history=max(7,min(180,(int)($_GET['history']??$_POST['history']??env('PRODUCTION_HISTORY_DAYS','28'))));
$days=max(1,min(60,(int)($_GET['days']??$_POST['days']??7)));
$safety=max(0,min(30,(int)($_GET['safety']??$_POST['safety']??env('PRODUCTION_SAFETY_DAYS','2'))));

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf($_POST['_csrf']??null);
    try{
        if((string)($_POST['action']??'')!=='create_draft')throw new InvalidArgumentException('Unsupported procurement action.');
        $supplierId=(int)($_POST['supplier_id']??0);
        $poId=$svc->createRecommendedDraft($supplierId,$history,$days,$safety,(int)$_SESSION['admin_id']);
        $audit->record((int)$_SESSION['admin_id'],'procurement_plan_draft_po','purchase_order',$poId,'Draft purchase order generated from ingredient procurement plan.',[],['supplier_id'=>$supplierId,'history'=>$history,'days'=>$days,'safety'=>$safety]);
        $notice='Draft purchase order created.';
    }catch(Throwable $e){$error=$e->getMessage();}
}
$plan=$svc->plan($history,$days,$safety);
$supplierGroups=[];foreach($plan['rows'] as $row){$sid=(int)($row['recommended_supplier_id']??0);if($sid>0&&(float)$row['suggested_order_quantity']>0)$supplierGroups[$sid]=$row['recommended_supplier_name'];}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Procurement Plan · Fudge Donuts Admin</title><link rel="stylesheet" href="<?=htmlspecialchars(asset_url('/assets/app.css'))?>"></head><body class="admin-body">
<a class="skip-link" href="#admin-main">Skip to admin content</a>
<header class="admin-topbar"><a class="admin-brand" href="/admin.php">Fudge Donuts <span>Admin</span></a><nav><a href="/admin.php">Dashboard</a><a href="/admin-inventory.php">Inventory</a><a href="/admin-suppliers.php">Suppliers</a><a href="/admin-purchase-orders.php">Purchase Orders</a><a class="active" href="/admin-procurement-plan.php">Procurement Plan</a><a href="/admin-recipes.php">Recipes</a><a href="/admin-batches.php">Batches</a></nav></header>
<main id="admin-main" tabindex="-1" class="admin-shell">
<div class="admin-page-head"><div><p class="eyebrow">Ingredient forecasting</p><h1>Procurement Plan</h1><p class="admin-welcome">Translate projected donut production into ingredient purchasing requirements.</p></div><a class="button secondary" href="/admin-procurement-plan.csv.php?history=<?=$history?>&days=<?=$days?>&safety=<?=$safety?>">Export CSV</a></div>
<?php if($error):?><div class="notice error" role="alert"><?=htmlspecialchars($error)?></div><?php endif;?><?php if($notice):?><div class="notice" role="status"><?=htmlspecialchars($notice)?></div><?php endif;?>

<section class="dashboard-panel"><form method="get" class="inline-admin production-plan-controls"><label>Sales history<input type="number" name="history" min="7" max="180" value="<?=$history?>"></label><label>Forecast days<input type="number" name="days" min="1" max="60" value="<?=$days?>"></label><label>Safety days<input type="number" name="safety" min="0" max="30" value="<?=$safety?>"></label><button class="button secondary">Recalculate</button></form></section>

<section class="dashboard-kpis"><article class="kpi-card"><span>Ingredients</span><strong><?=$plan['totals']['ingredients']?></strong></article><article class="kpi-card <?=$plan['totals']['shortages']?'kpi-alert':''?>"><span>Shortages</span><strong><?=$plan['totals']['shortages']?></strong></article><article class="kpi-card <?=$plan['totals']['critical']?'kpi-alert':''?>"><span>No supplier</span><strong><?=$plan['totals']['critical']?></strong></article><article class="kpi-card"><span>Suggested PO value</span><strong><?=money((int)$plan['totals']['estimated_order_cents'])?></strong></article></section>

<?php if($plan['missing_recipe_flavors']):?><div class="notice error" role="alert"><strong>Recipe coverage required:</strong> <?php foreach($plan['missing_recipe_flavors'] as $i=>$f):?><?=$i?', ':''?><a href="/admin-recipes.php?flavor_id=<?=(int)$f['flavor_id']?>"><?=htmlspecialchars($f['name'])?></a> (<?=(int)$f['suggested_prep']?> suggested)<?php endforeach;?>. Ingredient demand cannot be calculated for these flavors until an active recipe exists.</div><?php endif;?>

<?php if($supplierGroups):?><section class="dashboard-panel"><div class="panel-head"><div><p class="eyebrow">Recommended purchasing</p><h2>Create draft POs</h2></div></div><div class="actions"><?php foreach($supplierGroups as $sid=>$name):?><form method="post"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="create_draft"><input type="hidden" name="supplier_id" value="<?=$sid?>"><input type="hidden" name="history" value="<?=$history?>"><input type="hidden" name="days" value="<?=$days?>"><input type="hidden" name="safety" value="<?=$safety?>"><button class="button">Create <?=htmlspecialchars($name)?> draft PO</button></form><?php endforeach;?></div></section><?php endif;?>

<section class="dashboard-panel"><div class="dashboard-table-wrap"><table class="dashboard-table procurement-plan-table"><thead><tr><th>Ingredient</th><th>Required</th><th>Usable stock</th><th>Draft PO</th><th>On order</th><th>Net shortage</th><th>Recommended purchase</th><th>Risk</th></tr></thead><tbody>
<?php if(!$plan['rows']):?><tr><td colspan="8" class="empty-cell">No ingredient demand is projected for this horizon.</td></tr><?php endif;?>
<?php foreach($plan['rows'] as $row):?><tr><td><strong><?=htmlspecialchars($row['ingredient_name'])?></strong><small><?=htmlspecialchars($row['quantity_unit'])?> · <?=count($row['flavors'])?> flavor<?=count($row['flavors'])===1?'':'s'?></small></td><td><?=htmlspecialchars(rtrim(rtrim(number_format((float)$row['required_quantity'],3,'.',''),'0'),'.'))?></td><td><?=htmlspecialchars(rtrim(rtrim(number_format((float)$row['available_quantity'],3,'.',''),'0'),'.'))?></td><td><?=htmlspecialchars(rtrim(rtrim(number_format((float)$row['draft_po_quantity'],3,'.',''),'0'),'.'))?></td><td><?=htmlspecialchars(rtrim(rtrim(number_format((float)$row['ordered_po_quantity'],3,'.',''),'0'),'.'))?></td><td><?=htmlspecialchars(rtrim(rtrim(number_format((float)$row['net_shortage'],3,'.',''),'0'),'.'))?></td><td><?php if((float)$row['suggested_order_quantity']>0):?><strong><?=htmlspecialchars(rtrim(rtrim(number_format((float)$row['suggested_order_quantity'],3,'.',''),'0'),'.').' '.$row['quantity_unit'])?></strong><small><?=htmlspecialchars($row['recommended_supplier_name']?:'No supplier')?><?php if($row['unit_cost_cents']!==null):?> · <?=money((int)$row['unit_cost_cents'])?> / <?=htmlspecialchars($row['quantity_unit'])?> · <?=(int)$row['lead_time_days']?>d<?php endif;?></small><?php else:?>—<?php endif;?></td><td><span class="production-risk production-risk-<?=htmlspecialchars($row['risk'])?>"><?=htmlspecialchars(str_replace('-',' ',$row['risk']))?></span></td></tr><?php endforeach;?>
</tbody></table></div></section>
</main></body></html>