<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{AdminAuditService,Database,FinishedGoodsReplenishmentService,ProductionSchedulingService};
require_admin_roles(['super_admin','admin','fulfillment']);

$db=Database::connection();$svc=new FinishedGoodsReplenishmentService($db);$audit=new AdminAuditService($db);$error='';$notice=(string)($_SESSION['replenishment_flash']??'');unset($_SESSION['replenishment_flash']);
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf($_POST['_csrf']??null);
    try{
        $action=(string)($_POST['action']??'');
        if($action==='settings'){
            $svc->saveSettings((int)($_POST['history_days']??28),(int)($_POST['safety_days']??2),(int)($_POST['minimum_run_units']??6));
            $audit->record((int)$_SESSION['admin_id'],'finished_goods_replenishment_settings_updated','finished_goods_replenishment','global','Finished-goods replenishment settings updated.',[],[
                'history_days'=>(int)($_POST['history_days']??28),'safety_days'=>(int)($_POST['safety_days']??2),'minimum_run_units'=>(int)($_POST['minimum_run_units']??6)
            ]);
            $_SESSION['replenishment_flash']='Replenishment settings saved.';
        }elseif($action==='schedule'){
            $flavorId=(int)($_POST['flavor_id']??0);$qty=(int)($_POST['quantity']??0);$date=(string)($_POST['scheduled_date']??'');
            $workId=$svc->createWorkOrder($flavorId,$qty,$date,(int)$_SESSION['admin_id']);
            $work=(new ProductionSchedulingService($db))->workOrders(null,500);$number='work order #'.$workId;
            foreach($work as $row)if((int)$row['id']===$workId){$number=$row['work_order_number'];break;}
            $audit->record((int)$_SESSION['admin_id'],'finished_goods_replenishment_scheduled','production_work_order',$workId,'Production work order created from replenishment recommendation.',[],['flavor_id'=>$flavorId,'quantity'=>$qty,'scheduled_date'=>$date]);
            $_SESSION['replenishment_flash']=$number.' created from replenishment demand.';
        }else throw new InvalidArgumentException('Unsupported replenishment action.');
        header('Location: /admin-replenishment.php',true,303);exit;
    }catch(Throwable $e){$error=$e->getMessage();}
}
$plan=$svc->recommendations();$settings=$plan['settings'];$events=$svc->events(100);$today=gmdate('Y-m-d');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Replenishment · Fudge Donuts Admin</title><link rel="stylesheet" href="/assets/app.css"></head><body class="admin-body">
<header class="admin-topbar"><a class="admin-brand" href="/admin.php">Fudge Donuts <span>Admin</span></a><nav><a href="/admin.php">Dashboard</a><a href="/admin-production-schedule.php">Production</a><a class="active" href="/admin-replenishment.php">Replenishment</a><a href="/admin-batch-allocation.php">Allocation</a><a href="/admin-finished-goods-aging.php">Aging</a><a href="/admin-production-qa.php">QA & Waste</a></nav></header>
<main class="admin-shell">
<div class="admin-page-head"><div><p class="eyebrow">Production demand</p><h1>Finished-Goods Replenishment</h1><p class="admin-welcome">Convert open customer demand, safe finished goods, sales velocity and already-scheduled production into a make-next queue.</p></div></div>
<?php if($error):?><div class="notice error" role="alert"><?=htmlspecialchars($error)?></div><?php endif;?><?php if($notice):?><div class="notice" role="status"><?=htmlspecialchars($notice)?></div><?php endif;?>
<section class="dashboard-kpis">
<article class="kpi-card <?=($plan['totals']['shortage_flavors']>0?'kpi-alert':'')?>"><span>Flavors to make</span><strong><?=$plan['totals']['shortage_flavors']?></strong><small>Net replenishment shortage</small></article>
<article class="kpi-card"><span>Open demand</span><strong><?=$plan['totals']['open_demand']?></strong><small>Units in Paid / Preparing / Ready orders</small></article>
<article class="kpi-card"><span>Safe finished goods</span><strong><?=$plan['totals']['safe_stock']?></strong><small>Active, unexpired units</small></article>
<article class="kpi-card"><span>Recommended production</span><strong><?=$plan['totals']['recommended']?></strong><small>Units after scheduled work</small></article>
</section>
<section class="dashboard-grid dashboard-secondary">
<div class="dashboard-panel">
<div class="panel-head"><div><p class="eyebrow">Make-next queue</p><h2>Production recommendations</h2></div></div>
<div class="dashboard-table-wrap"><table class="dashboard-table"><thead><tr><th>Flavor</th><th>Demand</th><th>Safe stock</th><th>Scheduled</th><th>Velocity</th><th>Recommended</th><th>Schedule</th></tr></thead><tbody>
<?php if(!$plan['rows']):?><tr><td colspan="7" class="empty-cell">No active flavors.</td></tr><?php endif;?>
<?php foreach($plan['rows'] as $row):?><tr>
<td><strong><?=htmlspecialchars($row['name'])?></strong><small><span class="replenishment-state replenishment-state-<?=htmlspecialchars($row['risk'])?>"><?=htmlspecialchars(ucfirst($row['risk']))?></span><?php if($row['days_cover']!==null):?> · <?=$row['days_cover']?> days cover<?php endif;?></small></td>
<td><?=(int)$row['open_demand']?><small>+ <?=(int)$row['safety_units']?> safety</small></td>
<td><?=(int)$row['safe_stock']?></td><td><?=(int)$row['scheduled_units']?></td><td><?=$row['daily_velocity']?>/day</td>
<td><strong><?=(int)$row['recommended_units']?></strong></td>
<td><?php if((int)$row['recommended_units']>0):?><form method="post" class="replenishment-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="schedule"><input type="hidden" name="flavor_id" value="<?=(int)$row['flavor_id']?>"><input type="number" name="quantity" min="1" max="100000" value="<?=(int)$row['recommended_units']?>" aria-label="Production quantity for <?=htmlspecialchars($row['name'])?>"><input type="date" name="scheduled_date" min="<?=$today?>" value="<?=$today?>" aria-label="Production date for <?=htmlspecialchars($row['name'])?>"><button class="link">Create work order</button></form><?php else:?><span class="muted">Covered</span><?php endif;?></td>
</tr><?php endforeach;?></tbody></table></div></div>
<div class="dashboard-panel"><div class="panel-head"><div><p class="eyebrow">Policy</p><h2>Replenishment settings</h2></div></div><form method="post" class="admin-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="settings"><label>Sales history window<input type="number" name="history_days" min="7" max="180" value="<?=(int)$settings['history_days']?>"></label><label>Safety stock days<input type="number" name="safety_days" min="0" max="30" value="<?=(int)$settings['safety_days']?>"></label><label>Minimum production run<input type="number" name="minimum_run_units" min="1" max="10000" value="<?=(int)$settings['minimum_run_units']?>"></label><button class="button secondary">Save policy</button></form><div class="allergen-callout"><strong>Safe stock only</strong><p>Held, recalled, expired and depleted production batches do not count toward replenishment coverage. Planned and in-progress work orders are deducted before another run is recommended.</p></div></div>
</section>
<section class="dashboard-panel"><div class="panel-head"><div><p class="eyebrow">Audit history</p><h2>Recent replenishment actions</h2></div><a href="/admin-production-schedule.php">Production schedule →</a></div><div class="dashboard-table-wrap"><table class="dashboard-table"><thead><tr><th>When</th><th>Flavor</th><th>Recommended</th><th>Scheduled</th><th>Work order</th><th>Operator</th></tr></thead><tbody><?php if(!$events):?><tr><td colspan="6" class="empty-cell">No replenishment actions recorded.</td></tr><?php endif;?><?php foreach($events as $e):?><tr><td><?=htmlspecialchars($e['created_at'])?></td><td><?=htmlspecialchars($e['flavor_name'])?></td><td><?=(int)$e['recommended_units']?></td><td><?=(int)$e['scheduled_units']?></td><td><?=htmlspecialchars((string)($e['work_order_number']??''))?></td><td><?=htmlspecialchars((string)($e['admin_email']??''))?></td></tr><?php endforeach;?></tbody></table></div></section>
</main></body></html>