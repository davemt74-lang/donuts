<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{AdminAuditService,Database,FinishedGoodsAllocationService};
require_admin_roles(['super_admin','admin','fulfillment']);

$db=Database::connection();$svc=new FinishedGoodsAllocationService($db);$audit=new AdminAuditService($db);$error='';$notice=(string)($_SESSION['allocation_flash']??'');unset($_SESSION['allocation_flash']);
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf($_POST['_csrf']??null);
    try{
        $orderId=(int)($_POST['order_id']??0);
        $plan=$svc->assign($orderId,(int)$_SESSION['admin_id']);
        $audit->record((int)$_SESSION['admin_id'],'finished_goods_fefo_allocated','order',$orderId,'Finished goods automatically allocated by FEFO.',[],['assignments'=>$plan['assignments']]);
        $_SESSION['allocation_flash']=$plan['already_assigned']?'Order was already allocated.':'Safe FEFO batches allocated to order.';
        header('Location: /admin-batch-allocation.php',true,303);exit;
    }catch(Throwable $e){$error=$e->getMessage();}
}
$summary=$svc->summary();$rows=$svc->queue(200);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Batch Allocation · Fudge Donuts Admin</title><link rel="stylesheet" href="/assets/app.css"></head><body class="admin-body">
<header class="admin-topbar"><a class="admin-brand" href="/admin.php">Fudge Donuts <span>Admin</span></a><nav><a href="/admin.php">Dashboard</a><a href="/admin-orders.php">Orders</a><a href="/admin-production-schedule.php">Production</a><a href="/admin-replenishment.php">Replenishment</a><a href="/admin-cycle-counts.php">Cycle Counts</a><a href="/admin-batches.php">Batches</a><a class="active" href="/admin-batch-allocation.php">Allocation</a><a href="/admin-shipping.php">Shipping</a></nav></header>
<main class="admin-shell"><div class="admin-page-head"><div><p class="eyebrow">Finished goods</p><h1>FEFO Batch Allocation</h1><p class="admin-welcome">Match Preparing and Ready orders to the earliest safe finished-goods batches before shipment.</p></div></div>
<?php if($error):?><div class="notice error" role="alert"><?=htmlspecialchars($error)?></div><?php endif;?><?php if($notice):?><div class="notice" role="status"><?=htmlspecialchars($notice)?></div><?php endif;?>
<section class="dashboard-kpis"><article class="kpi-card"><span>Waiting</span><strong><?=$summary['waiting']?></strong><small>Orders not yet assigned</small></article><article class="kpi-card"><span>Allocatable</span><strong><?=$summary['allocatable']?></strong><small>Safe stock available now</small></article><article class="kpi-card <?=($summary['shortage']>0?'kpi-alert':'')?>"><span>Shortage</span><strong><?=$summary['shortage']?></strong><small>Insufficient safe finished goods</small></article><article class="kpi-card"><span>Assigned</span><strong><?=$summary['assigned']?></strong><small>Traceability complete</small></article></section>
<section class="dashboard-panel"><div class="panel-head"><div><p class="eyebrow">Fulfillment queue</p><h2>Production-batch readiness</h2></div><a href="/admin-production-schedule.php">Production schedule →</a></div><div class="dashboard-table-wrap"><table class="dashboard-table"><thead><tr><th>Order</th><th>Customer</th><th>Status</th><th>Allocation</th><th>Shortage</th><th>Action</th></tr></thead><tbody>
<?php if(!$rows):?><tr><td colspan="6" class="empty-cell">No Preparing or Ready orders.</td></tr><?php endif;?>
<?php foreach($rows as $row):?><tr><td><a href="/admin-order.php?id=<?=(int)$row['id']?>"><strong><?=htmlspecialchars($row['order_number'])?></strong></a><small><?=htmlspecialchars($row['created_at'])?></small></td><td><?=htmlspecialchars(trim($row['first_name'].' '.$row['last_name']))?></td><td><?=htmlspecialchars(ucfirst($row['status']))?></td><td><span class="allocation-state allocation-state-<?=htmlspecialchars($row['allocation_state'])?>"><?=htmlspecialchars(ucfirst($row['allocation_state']))?></span></td><td><?=(int)$row['shortage_units']?></td><td><?php if($row['allocation_state']==='allocatable'):?><form method="post"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="order_id" value="<?=(int)$row['id']?>"><button class="button secondary">Auto-allocate FEFO</button></form><?php elseif($row['allocation_state']==='shortage'):?><a class="link" href="/admin-production-schedule.php">Schedule production</a><?php elseif($row['allocation_state']==='assigned'):?><a class="link" href="/admin-order-batches.php?id=<?=(int)$row['id']?>">View batches</a><?php else:?><a class="link" href="/admin-order-batches.php?id=<?=(int)$row['id']?>">Review manually</a><?php endif;?></td></tr><?php endforeach;?>
</tbody></table></div></section>
<div class="allergen-callout"><strong>Allocation rule</strong><p>FEFO uses the earliest best-by date first, then oldest production time. Held, recalled, expired, future-dated, depleted, or ingredient-unsafe batches are skipped. The canonical assignment path revalidates every selected batch before quantities are consumed.</p></div>
</main></body></html>