<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{AdminAuditService,Database,FinishedGoodsCycleCountService};
require_admin_roles(['super_admin','admin','fulfillment']);

$db=Database::connection();$svc=new FinishedGoodsCycleCountService($db);$audit=new AdminAuditService($db);$error='';$notice=(string)($_SESSION['cycle_count_flash']??'');unset($_SESSION['cycle_count_flash']);
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf($_POST['_csrf']??null);
    try{
        $action=(string)($_POST['action']??'');
        if($action==='settings'){
            $days=(int)($_POST['count_frequency_days']??7);$svc->setFrequencyDays($days);
            $audit->record((int)$_SESSION['admin_id'],'finished_goods_cycle_count_settings_updated','finished_goods_cycle_count','global','Cycle-count frequency updated.',[],['count_frequency_days'=>$days]);
            $_SESSION['cycle_count_flash']='Cycle-count frequency saved.';
        }elseif($action==='count'){
            $batchId=(int)($_POST['batch_id']??0);$qty=(int)($_POST['counted_quantity']??0);$reason=(string)($_POST['reason']??'routine');$notes=(string)($_POST['notes']??'');
            $id=$svc->reconcile($batchId,$qty,$reason,$notes,(int)$_SESSION['admin_id']);
            $audit->record((int)$_SESSION['admin_id'],'finished_goods_cycle_count_recorded','finished_goods_cycle_count',$id,'Finished-goods cycle count reconciled.',[],['batch_id'=>$batchId,'counted_quantity'=>$qty,'reason'=>$reason]);
            $_SESSION['cycle_count_flash']='Cycle count recorded and inventory reconciled.';
        }else throw new InvalidArgumentException('Unsupported cycle-count action.');
        header('Location: /admin-cycle-counts.php',true,303);exit;
    }catch(Throwable $e){$error=$e->getMessage();}
}
$summary=$svc->summary();$rows=$svc->queue();$history=$svc->history(100);$frequency=$svc->frequencyDays();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Cycle Counts · Fudge Donuts Admin</title><link rel="stylesheet" href="/assets/app.css"></head><body class="admin-body">
<header class="admin-topbar"><a class="admin-brand" href="/admin.php">Fudge Donuts <span>Admin</span></a><nav><a href="/admin.php">Dashboard</a><a href="/admin-replenishment.php">Replenishment</a><a href="/admin-cycle-counts.php" class="active">Cycle Counts</a><a href="/admin-batch-allocation.php">Allocation</a><a href="/admin-finished-goods-aging.php">Aging</a></nav></header>
<main class="admin-shell">
<div class="admin-page-head"><div><p class="eyebrow">Inventory accuracy</p><h1>Finished-Goods Cycle Counts</h1><p class="admin-welcome">Compare physical batch stock to system quantity and reconcile controlled variances without recreating allocated or dispositioned units.</p></div></div>
<?php if($error):?><div class="notice error" role="alert"><?=htmlspecialchars($error)?></div><?php endif;?><?php if($notice):?><div class="notice" role="status"><?=htmlspecialchars($notice)?></div><?php endif;?>
<section class="dashboard-kpis"><article class="kpi-card <?=($summary['never']>0?'kpi-alert':'')?>"><span>Never counted</span><strong><?=$summary['never']?></strong><small>Active/held batches</small></article><article class="kpi-card <?=($summary['due']>0?'kpi-alert':'')?>"><span>Due</span><strong><?=$summary['due']?></strong><small>Older than <?=$frequency?> days</small></article><article class="kpi-card"><span>Variance · 30d</span><strong><?=$summary['variance_units_30d']?></strong><small>Absolute units adjusted</small></article><article class="kpi-card"><span>Shrink · 30d</span><strong><?=$summary['negative_variance_units_30d']?></strong><small>Negative variance units</small></article></section>
<section class="dashboard-grid dashboard-secondary">
<div class="dashboard-panel"><div class="panel-head"><div><p class="eyebrow">Count queue</p><h2>Active finished goods</h2></div></div><div class="dashboard-table-wrap"><table class="dashboard-table"><thead><tr><th>Batch</th><th>Flavor</th><th>System</th><th>Last count</th><th>State</th><th>Physical count</th></tr></thead><tbody>
<?php if(!$rows):?><tr><td colspan="6" class="empty-cell">No active or held finished-goods batches to count.</td></tr><?php endif;?>
<?php foreach($rows as $row):?><tr><td><a href="/admin-batches.php?id=<?=(int)$row['id']?>"><strong><?=htmlspecialchars($row['batch_code'])?></strong></a><small><?=htmlspecialchars($row['status'])?></small></td><td><?=htmlspecialchars($row['flavor_name'])?></td><td><?=(int)$row['quantity_remaining']?></td><td><?=htmlspecialchars((string)($row['last_counted_at']??'Never'))?></td><td><span class="cycle-state cycle-state-<?=htmlspecialchars($row['count_state'])?>"><?=htmlspecialchars(ucfirst($row['count_state']))?></span></td><td><form method="post" class="cycle-count-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="count"><input type="hidden" name="batch_id" value="<?=(int)$row['id']?>"><input type="number" name="counted_quantity" min="0" max="100000" value="<?=(int)$row['quantity_remaining']?>" aria-label="Physical count for <?=htmlspecialchars($row['batch_code'])?>"><select name="reason" aria-label="Variance reason"><option value="routine">Routine / no variance</option><option value="shrinkage">Shrinkage</option><option value="damage">Damage</option><option value="found_stock">Found stock</option><option value="correction">Correction</option><option value="other">Other</option></select><input name="notes" maxlength="2000" placeholder="Notes" aria-label="Cycle-count notes"><button class="link">Reconcile</button></form></td></tr><?php endforeach;?>
</tbody></table></div></div>
<div class="dashboard-panel"><div class="panel-head"><div><p class="eyebrow">Policy</p><h2>Count frequency</h2></div></div><form method="post" class="admin-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="settings"><label>Count every<input type="number" name="count_frequency_days" min="1" max="90" value="<?=$frequency?>"></label><button class="button secondary">Save count frequency</button></form><div class="allergen-callout"><strong>Controlled reconciliation</strong><p>Positive counts cannot exceed produced units minus stock already assigned to orders or dispositioned. Every variance is written to a permanent count history.</p></div></div>
</section>
<section class="dashboard-panel"><div class="panel-head"><div><p class="eyebrow">Audit history</p><h2>Recent counts</h2></div></div><div class="dashboard-table-wrap"><table class="dashboard-table"><thead><tr><th>When</th><th>Batch</th><th>Flavor</th><th>System</th><th>Counted</th><th>Variance</th><th>Reason</th><th>Operator</th></tr></thead><tbody><?php if(!$history):?><tr><td colspan="8" class="empty-cell">No cycle counts recorded.</td></tr><?php endif;?><?php foreach($history as $h):?><tr><td><?=htmlspecialchars($h['created_at'])?></td><td><?=htmlspecialchars($h['batch_code'])?></td><td><?=htmlspecialchars($h['flavor_name'])?></td><td><?=(int)$h['system_quantity']?></td><td><?=(int)$h['counted_quantity']?></td><td class="<?=((int)$h['variance_quantity']<0?'danger':'')?>"><?=((int)$h['variance_quantity']>0?'+':'').(int)$h['variance_quantity']?></td><td><?=htmlspecialchars(ucwords(str_replace('_',' ',$h['reason'])))?></td><td><?=htmlspecialchars((string)($h['admin_email']??''))?></td></tr><?php endforeach;?></tbody></table></div></section>
</main></body></html>