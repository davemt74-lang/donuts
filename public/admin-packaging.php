<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{AdminAuditService,CatalogRepository,Database,PackagingInventoryService};
require_admin_roles(['super_admin','admin','fulfillment']);

$db=Database::connection();$svc=new PackagingInventoryService($db);$audit=new AdminAuditService($db);$catalog=new CatalogRepository($db);$error='';$notice=(string)($_SESSION['packaging_flash']??'');unset($_SESSION['packaging_flash']);
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf($_POST['_csrf']??null);
    try{
        $action=(string)($_POST['action']??'');
        if($action==='material'){
            $id=$svc->saveMaterial($_POST,(int)$_SESSION['admin_id']);
            $audit->record((int)$_SESSION['admin_id'],'packaging_material_saved','packaging_material',$id,'Packaging material configuration saved.',[],['sku'=>(string)($_POST['sku']??''),'name'=>(string)($_POST['name']??'')]);
            $_SESSION['packaging_flash']='Packaging material saved.';
        }elseif($action==='receive'){
            $id=(int)$_POST['material_id'];$qty=(int)$_POST['quantity'];$svc->receive($id,$qty,(string)($_POST['reason']??''),(int)$_SESSION['admin_id']);
            $audit->record((int)$_SESSION['admin_id'],'packaging_received','packaging_material',$id,'Packaging stock received.',[],['quantity'=>$qty]);
            $_SESSION['packaging_flash']='Packaging receipt recorded.';
        }elseif($action==='adjust'){
            $id=(int)$_POST['material_id'];$qty=(int)$_POST['new_quantity'];$svc->adjust($id,$qty,(string)($_POST['reason']??''),(int)$_SESSION['admin_id']);
            $audit->record((int)$_SESSION['admin_id'],'packaging_adjusted','packaging_material',$id,'Packaging stock adjusted.',[],['new_quantity'=>$qty]);
            $_SESSION['packaging_flash']='Packaging stock reconciled.';
        }elseif($action==='requirement'){
            $pack=(int)$_POST['pack_size_id'];$material=(int)$_POST['material_id'];$qty=(int)$_POST['quantity_per_box'];$svc->setRequirement($pack,$material,$qty);
            $audit->record((int)$_SESSION['admin_id'],'packaging_requirement_updated','pack_size',$pack,'Pack packaging requirement updated.',[],['material_id'=>$material,'quantity_per_box'=>$qty]);
            $_SESSION['packaging_flash']='Packaging requirement saved.';
        }else throw new InvalidArgumentException('Unsupported packaging action.');
        header('Location: /admin-packaging.php',true,303);exit;
    }catch(Throwable $e){$error=$e->getMessage();}
}
$materials=$svc->materials();$plan=$svc->plan();$requirements=$svc->requirements();$packs=$catalog->packs(false);
$reqMap=[];foreach($requirements as $r)$reqMap[(int)$r['pack_size_id']][(int)$r['material_id']]=(int)$r['quantity_per_box'];
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Packaging Inventory · Fudge Donuts Admin</title><link rel="stylesheet" href="/assets/app.css"></head><body class="admin-body">
<header class="admin-topbar"><a class="admin-brand" href="/admin.php">Fudge Donuts <span>Admin</span></a><nav><a href="/admin.php">Dashboard</a><a href="/admin-inventory.php">Inventory</a><a class="active" href="/admin-packaging.php">Packaging</a><a href="/admin-packaging-purchasing.php">Packaging POs</a><a href="/admin-orders.php">Orders</a><a href="/admin-replenishment.php">Replenishment</a></nav></header>
<main class="admin-shell">
<div class="admin-page-head"><div><p class="eyebrow">Fulfillment supplies</p><h1>Packaging Inventory</h1><p class="admin-welcome">Track boxes, wrappers, labels and other materials required to prepare paid orders.</p></div></div>
<?php if($error):?><div class="notice error" role="alert"><?=htmlspecialchars($error)?></div><?php endif;?><?php if($notice):?><div class="notice" role="status"><?=htmlspecialchars($notice)?></div><?php endif;?>
<section class="dashboard-kpis"><article class="kpi-card <?=($plan['shortage_materials']>0?'kpi-alert':'')?>"><span>Reorder materials</span><strong><?=$plan['shortage_materials']?></strong><small>Below demand + reorder point</small></article><article class="kpi-card"><span>Suggested purchase</span><strong><?=$plan['suggested_purchase_units']?></strong><small>Total packaging units</small></article><article class="kpi-card"><span>Active materials</span><strong><?=count(array_filter($materials,fn($m)=>(int)$m['active']===1))?></strong><small>Packaging SKUs</small></article><article class="kpi-card"><span>Reserved</span><strong><?=array_sum(array_map(fn($m)=>(int)$m['reserved_units'],$materials))?></strong><small>Allocated to Preparing orders</small></article></section>

<section class="dashboard-panel"><div class="panel-head"><div><p class="eyebrow">Supply plan</p><h2>Packaging readiness</h2></div></div><div class="dashboard-table-wrap"><table class="dashboard-table"><thead><tr><th>Material</th><th>On hand</th><th>Paid-order demand</th><th>Reorder point</th><th>Shortage</th><th>Suggested buy</th></tr></thead><tbody>
<?php foreach($plan['rows'] as $row):?><tr><td><strong><?=htmlspecialchars($row['name'])?></strong><small><?=htmlspecialchars($row['sku'])?> · <?=htmlspecialchars($row['unit'])?></small></td><td><?=(int)$row['stock_on_hand']?></td><td><?=(int)$row['paid_order_demand']?></td><td><?=(int)$row['reorder_point']?></td><td class="<?=$row['shortage_units']>0?'danger':''?>"><?=(int)$row['shortage_units']?></td><td><strong><?=(int)$row['suggested_purchase']?></strong></td></tr><?php endforeach;?>
</tbody></table></div></section>

<section class="dashboard-grid dashboard-secondary"><div class="dashboard-panel"><div class="panel-head"><div><p class="eyebrow">Stock</p><h2>Materials</h2></div></div>
<?php foreach($materials as $m):?><article class="packaging-material-card"><div><strong><?=htmlspecialchars($m['name'])?></strong><small><?=htmlspecialchars($m['sku'])?> · <?=(int)$m['stock_on_hand']?> <?=htmlspecialchars($m['unit'])?> available</small></div>
<div class="packaging-actions">
<form method="post"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="receive"><input type="hidden" name="material_id" value="<?=(int)$m['id']?>"><label class="sr-only" for="receive-<?=(int)$m['id']?>">Receive <?=htmlspecialchars($m['name'])?></label><input id="receive-<?=(int)$m['id']?>" type="number" name="quantity" min="1" max="1000000" placeholder="Qty" required><input name="reason" maxlength="500" value="Packaging received" aria-label="Receipt reason"><button class="link">Receive</button></form>
<form method="post"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="adjust"><input type="hidden" name="material_id" value="<?=(int)$m['id']?>"><input type="number" name="new_quantity" min="0" max="1000000" value="<?=(int)$m['stock_on_hand']?>" aria-label="Physical count for <?=htmlspecialchars($m['name'])?>" required><input name="reason" maxlength="500" placeholder="Adjustment reason" aria-label="Adjustment reason" required><button class="link">Reconcile</button></form>
</div></article><?php endforeach;?>
</div>
<div class="dashboard-panel"><div class="panel-head"><div><p class="eyebrow">Catalog</p><h2>Add packaging material</h2></div></div><form method="post" class="admin-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="material"><label>SKU<input name="sku" required placeholder="RIBBON-BROWN"></label><label>Name<input name="name" required placeholder="Brown Satin Ribbon"></label><label>Unit<input name="unit" value="each"></label><div class="two"><label>Reorder point<input type="number" min="0" name="reorder_point" value="0"></label><label>Reorder quantity<input type="number" min="0" name="reorder_quantity" value="0"></label></div><label>Notes<textarea name="notes"></textarea></label><label class="check"><input type="checkbox" name="active" value="1" checked> Active</label><button class="button">Add material</button></form></div></section>

<section class="dashboard-panel"><div class="panel-head"><div><p class="eyebrow">Pack bill of materials</p><h2>Packaging requirements</h2></div></div><p class="muted">Set units required for one box of each pack size. Enter 0 to remove a requirement.</p><div class="dashboard-table-wrap"><table class="dashboard-table"><thead><tr><th>Pack</th><?php foreach($materials as $m):?><th><?=htmlspecialchars($m['name'])?></th><?php endforeach;?></tr></thead><tbody>
<?php foreach($packs as $pack):?><tr><td><strong><?=htmlspecialchars($pack['name'])?></strong></td><?php foreach($materials as $m):?><td><form method="post" class="packaging-requirement-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="requirement"><input type="hidden" name="pack_size_id" value="<?=(int)$pack['id']?>"><input type="hidden" name="material_id" value="<?=(int)$m['id']?>"><input type="number" min="0" max="1000" name="quantity_per_box" value="<?=(int)($reqMap[(int)$pack['id']][(int)$m['id']]??0)?>" aria-label="<?=htmlspecialchars($m['name'].' required for '.$pack['name'])?>"><button class="link">Save</button></form></td><?php endforeach;?></tr><?php endforeach;?>
</tbody></table></div></section>
</main></body></html>