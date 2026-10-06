<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{AdminAuditService,Database,ProductionQaService};
require_admin_roles(['super_admin','admin','fulfillment']);

$db=Database::connection();$svc=new ProductionQaService($db);$audit=new AdminAuditService($db);$error='';$notice=(string)($_SESSION['qa_flash']??'');unset($_SESSION['qa_flash']);
$id=(int)($_GET['id']??$_POST['id']??0);

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf($_POST['_csrf']??null);
    try{
        $action=(string)($_POST['action']??'');
        if($action==='check'){
            $key=(string)($_POST['check_key']??'');$result=(string)($_POST['result']??'');$notes=(string)($_POST['notes']??'');
            $svc->recordCheck($id,$key,$result,$notes,(int)$_SESSION['admin_id']);
            $audit->record((int)$_SESSION['admin_id'],'production_qa_check_recorded','production_work_order',$id,'Production QA check recorded.',[],['check_key'=>$key,'result'=>$result]);
            $_SESSION['qa_flash']='QA check saved.';
        }elseif($action==='waste'){
            $wasteId=$svc->recordWaste($id,(int)($_POST['quantity']??0),(string)($_POST['reason']??''),(string)($_POST['notes']??''),(int)$_SESSION['admin_id']);
            $audit->record((int)$_SESSION['admin_id'],'production_waste_recorded','production_waste',$wasteId,'Production waste recorded.',[],['work_order_id'=>$id,'quantity'=>(int)$_POST['quantity'],'reason'=>(string)$_POST['reason']]);
            $_SESSION['qa_flash']='Waste event recorded.';
        }elseif($action==='settings'){
            $svc->setYieldWarningPercent((int)($_POST['yield_warning_percent']??10));
            $audit->record((int)$_SESSION['admin_id'],'production_qa_settings_updated','production_qa','global','Production QA warning threshold updated.',[],['yield_warning_percent'=>(int)$_POST['yield_warning_percent']]);
            $_SESSION['qa_flash']='QA settings saved.';
        }else throw new InvalidArgumentException('Unsupported QA action.');
        header('Location: /admin-production-qa.php'.($id?'?id='.$id:''),true,303);exit;
    }catch(Throwable $e){$error=$e->getMessage();}
}

$summary=$svc->summary();$queue=$svc->queue();$details=$id?$svc->details($id):null;$warning=$svc->yieldWarningPercent();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Production QA · Fudge Donuts Admin</title><link rel="stylesheet" href="/assets/app.css"></head><body class="admin-body">
<header class="admin-topbar"><a class="admin-brand" href="/admin.php">Fudge Donuts <span>Admin</span></a><nav><a href="/admin.php">Dashboard</a><a href="/admin-production-schedule.php">Schedule</a><a class="active" href="/admin-production-qa.php">QA & Waste</a><a href="/admin-batches.php">Batches</a><a href="/admin-recipes.php">Recipes</a><a href="/admin-ingredient-lots.php">Ingredients</a></nav></header>
<main class="admin-shell"><div class="admin-page-head"><div><p class="eyebrow">Production control</p><h1>QA, Yield & Waste</h1><p class="admin-welcome">Sign off required production checks, record loss, and review yield variance before batch release.</p></div></div>
<?php if($error):?><div class="notice error" role="alert"><?=htmlspecialchars($error)?></div><?php endif;?><?php if($notice):?><div class="notice" role="status"><?=htmlspecialchars($notice)?></div><?php endif;?>

<section class="dashboard-kpis"><article class="kpi-card"><span>Waste · 30 days</span><strong><?=(int)$summary['waste_units']?></strong><small>Finished units recorded as loss</small></article><article class="kpi-card <?=((int)$summary['yield_warnings']>0?'kpi-alert':'')?>"><span>Yield warnings</span><strong><?=(int)$summary['yield_warnings']?></strong><small>Completed runs outside ±<?=$warning?>%</small></article><article class="kpi-card <?=((int)$summary['failed_qa_work_orders']>0?'kpi-alert':'')?>"><span>Failed QA</span><strong><?=(int)$summary['failed_qa_work_orders']?></strong><small>Work orders with an active failed check</small></article><article class="kpi-card"><span>QA threshold</span><strong>±<?=$warning?>%</strong><small>Yield variance warning</small></article></section>

<section class="dashboard-grid dashboard-secondary">
<div class="dashboard-panel"><div class="panel-head"><div><p class="eyebrow">Production queue</p><h2>Work orders</h2></div></div><div class="dashboard-table-wrap"><table class="dashboard-table"><thead><tr><th>Work order</th><th>Flavor</th><th>Status</th><th>QA</th><th>Waste</th><th>Yield</th></tr></thead><tbody><?php if(!$queue):?><tr><td colspan="6" class="empty-cell">No active or completed production work.</td></tr><?php endif;?><?php foreach($queue as $row):?><tr><td><a href="/admin-production-qa.php?id=<?=(int)$row['id']?>"><strong><?=htmlspecialchars($row['work_order_number'])?></strong></a><small><?=htmlspecialchars($row['scheduled_date'])?></small></td><td><?=htmlspecialchars($row['flavor_name'])?></td><td><?=htmlspecialchars(ucwords(str_replace('_',' ',$row['status'])))?></td><td><?php if($row['qa_ok']):?><span class="production-qa production-qa-pass">Passed</span><?php elseif($row['qa_failed']):?><span class="production-qa production-qa-fail"><?=$row['qa_failed']?> failed</span><?php else:?><span class="production-qa production-qa-pending"><?=$row['qa_missing']?> missing</span><?php endif;?></td><td><?=(int)$row['waste_units']?></td><td><?=$row['variance_percent']===null?'—':htmlspecialchars((string)$row['variance_percent']).'%'?></td></tr><?php endforeach;?></tbody></table></div></div>

<div class="dashboard-panel"><div class="panel-head"><div><p class="eyebrow">Controls</p><h2>Yield warning</h2></div></div><form method="post" class="admin-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="settings"><label>Warn when actual yield differs from plan by at least<input type="number" name="yield_warning_percent" min="1" max="100" value="<?=$warning?>"></label><button class="button secondary">Save threshold</button></form><?php if($summary['waste_by_reason']):?><h3>Waste by reason · 30 days</h3><div class="rank-list"><?php foreach($summary['waste_by_reason'] as $reason=>$qty):?><div><span><?=htmlspecialchars(ucwords(str_replace('_',' ',$reason)))?></span><strong><?=(int)$qty?></strong></div><?php endforeach;?></div><?php endif;?></div>
</section>

<?php if($details):$w=$details['work_order'];$qa=$details['qa'];$yield=$details['yield'];?>
<section class="dashboard-grid dashboard-secondary">
<div class="dashboard-panel"><div class="panel-head"><div><p class="eyebrow"><?=htmlspecialchars($w['work_order_number'])?></p><h2>Required QA signoff</h2></div><a href="/admin-production-schedule.php?status=<?=urlencode((string)$w['status'])?>">Schedule →</a></div><p><strong><?=htmlspecialchars($w['flavor_name'])?></strong> · planned <?=(int)$w['planned_quantity']?><?php if($yield['actual']!==null):?> · actual <?=(int)$yield['actual']?><?php endif;?></p>
<div class="qa-check-grid"><?php foreach(ProductionQaService::REQUIRED_CHECKS as $key=>$label):$check=$qa['checks'][$key]??null;?><form method="post" class="qa-check-card <?=($check?($check['result']==='pass'?'qa-card-pass':'qa-card-fail'):'')?>"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="check"><input type="hidden" name="id" value="<?=(int)$w['id']?>"><input type="hidden" name="check_key" value="<?=htmlspecialchars($key)?>"><h3><?=htmlspecialchars($label)?></h3><label>Result<select name="result"><option value="pass" <?=($check&&$check['result']==='pass'?'selected':'')?>>Pass</option><option value="fail" <?=($check&&$check['result']==='fail'?'selected':'')?>>Fail</option></select></label><label>Notes<input name="notes" maxlength="2000" value="<?=htmlspecialchars((string)($check['notes']??''))?>"></label><button class="button secondary">Save check</button><?php if($check):?><small>Checked <?=htmlspecialchars($check['checked_at'])?></small><?php endif;?></form><?php endforeach;?></div>
<?php if($qa['ok']):?><div class="notice">All required QA checks pass. This work order is eligible for completion.</div><?php elseif($qa['failed']):?><div class="notice error">Completion is blocked until failed QA checks are corrected and re-checked.</div><?php else:?><div class="notice">Complete all required QA checks before closing this work order.</div><?php endif;?></div>

<div class="dashboard-panel"><div class="panel-head"><div><p class="eyebrow">Loss control</p><h2>Waste & yield</h2></div></div><div class="review-total"><span>Planned</span><strong><?=$yield['planned']?></strong></div><div class="review-total"><span>Actual</span><strong><?=$yield['actual']===null?'—':$yield['actual']?></strong></div><div class="review-total"><span>Variance</span><strong><?=$yield['variance_percent']===null?'—':htmlspecialchars((string)$yield['variance_percent']).'%'?></strong></div><div class="review-total"><span>Recorded waste</span><strong><?=$yield['waste']?></strong></div>
<form method="post" class="admin-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="waste"><input type="hidden" name="id" value="<?=(int)$w['id']?>"><label>Waste quantity<input type="number" name="quantity" min="1" max="100000" required></label><label>Reason<select name="reason"><?php foreach(['quality','breakage','overproduction','spoilage','setup','other'] as $reason):?><option value="<?=$reason?>"><?=htmlspecialchars(ucwords($reason))?></option><?php endforeach;?></select></label><label>Notes<textarea name="notes" maxlength="2000"></textarea></label><button class="button">Record waste</button></form>
<?php if($details['waste']):?><div class="dashboard-table-wrap"><table class="dashboard-table"><thead><tr><th>When</th><th>Qty</th><th>Reason</th><th>Notes</th></tr></thead><tbody><?php foreach($details['waste'] as $event):?><tr><td><?=htmlspecialchars($event['created_at'])?></td><td><?=(int)$event['quantity']?></td><td><?=htmlspecialchars(ucwords($event['reason']))?></td><td><?=htmlspecialchars($event['notes'])?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></div>
</section><?php endif;?>
</main></body></html>