<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{AdminAuditService,CostAccountingService,Database};
require_admin_roles(['super_admin','admin']);

$db=Database::connection();$svc=new CostAccountingService($db);$audit=new AdminAuditService($db);$error='';$notice='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf($_POST['_csrf']??null);
    try{
        $action=(string)($_POST['action']??'');
        if($action==='flavor'){
            $id=(int)($_POST['id']??0);$before=array_values(array_filter($svc->flavorCosts(),fn($r)=>(int)$r['id']===$id))[0]??[];
            $svc->setFlavorCost($id,(int)($_POST['unit_cost_cents']??0));$after=array_values(array_filter($svc->flavorCosts(),fn($r)=>(int)$r['id']===$id))[0]??[];
            $audit->record((int)$_SESSION['admin_id'],'flavor_cost_updated','flavor',$id,'Flavor unit cost updated.',$before,$after);$notice='Flavor cost saved.';
        }elseif($action==='pack'){
            $id=(int)($_POST['id']??0);$before=array_values(array_filter($svc->packCosts(),fn($r)=>(int)$r['id']===$id))[0]??[];
            $svc->setPackCost($id,(int)($_POST['packaging_cost_cents']??0));$after=array_values(array_filter($svc->packCosts(),fn($r)=>(int)$r['id']===$id))[0]??[];
            $audit->record((int)$_SESSION['admin_id'],'packaging_cost_updated','pack',$id,'Packaging cost updated.',$before,$after);$notice='Packaging cost saved.';
        }else throw new InvalidArgumentException('Unsupported cost action.');
    }catch(Throwable $e){$error=$e->getMessage();}
}
$flavors=$svc->flavorCosts();$packs=$svc->packCosts();$summary=$svc->summary();$recent=$svc->recent(40);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Costs & Margin · Fudge Donuts Admin</title><link rel="stylesheet" href="<?=htmlspecialchars(asset_url('/assets/app.css'))?>"></head><body class="admin-body">
<header class="admin-topbar"><a class="admin-brand" href="/admin.php">Fudge Donuts <span>Admin</span></a><nav><a href="/admin.php">Dashboard</a><a href="/admin-orders.php">Orders</a><a href="/admin-inventory.php">Inventory</a><a class="active" href="/admin-costs.php">Costs & Margin</a><a href="/admin-reports.php">Reports</a><a href="/admin-audit.php">Audit</a></nav></header>
<main class="admin-shell"><div class="admin-page-head"><div><p class="eyebrow">Profitability</p><h1>Costs & Gross Margin</h1><p class="admin-welcome">Track flavor and packaging costs without changing storefront prices.</p></div></div>
<?php if($error):?><div class="notice error" role="alert"><?=htmlspecialchars($error)?></div><?php endif;?><?php if($notice):?><div class="notice" role="status"><?=htmlspecialchars($notice)?></div><?php endif;?>
<section class="dashboard-kpis"><article class="kpi-card"><span>Snapshotted orders</span><strong><?=(int)$summary['orders']?></strong><small>Historical cost records</small></article><article class="kpi-card"><span>Merchandise revenue</span><strong><?=money((int)$summary['revenue_basis_cents'])?></strong><small>Subtotal after discounts</small></article><article class="kpi-card"><span>Estimated COGS</span><strong><?=money((int)$summary['cost_cents'])?></strong><small>Flavor + packaging</small></article><article class="kpi-card"><span>Gross margin</span><strong><?=money((int)$summary['margin_cents'])?></strong><small><?=htmlspecialchars(number_format((float)$summary['margin_percent'],2))?>%</small></article></section>
<div class="dashboard-grid dashboard-secondary"><section class="dashboard-panel"><h2>Flavor unit costs</h2><div class="cost-editor-list"><?php foreach($flavors as $f):?><form method="post" class="inline-admin"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="flavor"><input type="hidden" name="id" value="<?=(int)$f['id']?>"><strong><?=htmlspecialchars($f['name'])?></strong><label>Cost per donut (cents)<input type="number" min="0" name="unit_cost_cents" value="<?=(int)$f['unit_cost_cents']?>"></label><button class="button secondary">Save</button></form><?php endforeach;?></div></section>
<section class="dashboard-panel"><h2>Packaging costs</h2><div class="cost-editor-list"><?php foreach($packs as $p):?><form method="post" class="inline-admin"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="pack"><input type="hidden" name="id" value="<?=(int)$p['id']?>"><strong><?=htmlspecialchars($p['name'])?></strong><label>Packaging per box (cents)<input type="number" min="0" name="packaging_cost_cents" value="<?=(int)$p['packaging_cost_cents']?>"></label><button class="button secondary">Save</button></form><?php endforeach;?></div><div class="allergen-callout"><strong>Gross margin scope</strong><p>Current COGS includes flavor unit cost and pack-level packaging only. Labor, payment fees, postage, rent, and other overhead are not included.</p></div></section></div>
<section class="dashboard-panel"><h2>Recent order margins</h2><div class="dashboard-table-wrap"><table class="dashboard-table"><thead><tr><th>Order</th><th>Revenue basis</th><th>Product</th><th>Packaging</th><th>COGS</th><th>Gross margin</th><th>Margin %</th></tr></thead><tbody><?php if(!$recent):?><tr><td colspan="7" class="empty-cell">No paid orders have cost snapshots yet.</td></tr><?php endif;?><?php foreach($recent as $row):?><tr><td><strong><?=htmlspecialchars($row['order_number'])?></strong><small><?=htmlspecialchars($row['created_at'])?></small></td><td><?=money((int)$row['revenue_basis_cents'])?></td><td><?=money((int)$row['product_cost_cents'])?></td><td><?=money((int)$row['packaging_cost_cents'])?></td><td><?=money((int)$row['total_cost_cents'])?></td><td><?=money((int)$row['gross_margin_cents'])?></td><td><?=htmlspecialchars(number_format((float)$row['margin_percent'],2))?>%</td></tr><?php endforeach;?></tbody></table></div></section>
</main></body></html>