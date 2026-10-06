<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{AdminAuditService,Database,ObservabilityService};
require_admin_roles(['super_admin','admin']);

$db=Database::connection();$svc=new ObservabilityService($db);$audit=new AdminAuditService($db);$error='';$notice='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf($_POST['_csrf']??null);
    try{
        $id=(int)($_POST['id']??0);
        $svc->resolve($id,(int)$_SESSION['admin_id']);
        $audit->record((int)$_SESSION['admin_id'],'operational_event_resolved','operational_event',$id,'Operational event resolved.');
        $notice='Operational event resolved.';
    }catch(Throwable $e){$error=$e->getMessage();}
}
$severity=trim((string)($_GET['severity']??''));$openOnly=($_GET['open']??'1')!=='0';
try{$rows=$svc->recent(250,$severity?:null,$openOnly);}catch(Throwable $e){$error=$e->getMessage();$severity='';$rows=$svc->recent(250,null,$openOnly);}
$stats=$svc->stats();$health=$svc->health();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Operations · Fudge Donuts Admin</title><link rel="stylesheet" href="/assets/app.css"></head><body class="admin-body">
<header class="admin-topbar"><a class="admin-brand" href="/admin.php">Fudge Donuts <span>Admin</span></a><nav><a href="/admin.php">Dashboard</a><a href="/admin-orders.php">Orders</a><a href="/admin-inventory.php">Inventory</a><a href="/admin-reports.php">Reports</a><a href="/admin-notifications.php">Email</a><a href="/admin-audit.php">Audit</a><a class="active" href="/admin-operations.php">Operations</a></nav></header>
<main class="admin-shell">
<div class="admin-page-head"><div><p class="eyebrow">Observability</p><h1>Store Operations</h1><p class="admin-welcome">Application errors, infrastructure warnings and launch-health signals in one place.</p></div><div class="status status-<?=htmlspecialchars($health['status']==='ok'?'paid':($health['status']==='degraded'?'ready':'payment_failed'))?>"><?=htmlspecialchars(strtoupper($health['status']))?></div></div>
<?php if($error):?><div class="notice error"><?=htmlspecialchars($error)?></div><?php endif;?><?php if($notice):?><div class="notice"><?=htmlspecialchars($notice)?></div><?php endif;?>
<section class="dashboard-kpis">
<article class="kpi-card"><span>Open events</span><strong><?=(int)$stats['open']?></strong><small>All unresolved signals</small></article>
<article class="kpi-card"><span>Errors</span><strong><?=(int)$stats['error']?></strong><small>Open application errors</small></article>
<article class="kpi-card <?=((int)$stats['critical']>0?'kpi-alert':'')?>"><span>Critical</span><strong><?=(int)$stats['critical']?></strong><small>Immediate operational attention</small></article>
<article class="kpi-card"><span>Today</span><strong><?=(int)$stats['today']?></strong><small>Events seen today</small></article>
</section>
<section class="dashboard-panel ops-health-grid">
<div><span>Failed email</span><strong><?=(int)$health['failed_email']?></strong></div>
<div><span>Expired reservations</span><strong><?=(int)$health['expired_reservations']?></strong></div>
<div><span>Payment review</span><strong><?=(int)$health['payment_review']?></strong></div>
<div><span>Open errors</span><strong><?=(int)$health['open_errors']?></strong></div>
</section>
<div class="order-filters"><a class="<?=!$severity?'active':''?>" href="/admin-operations.php?open=<?=$openOnly?'1':'0'?>">All</a><?php foreach(['critical','error','warning','info'] as $s):?><a class="<?=$severity===$s?'active':''?>" href="/admin-operations.php?severity=<?=$s?>&open=<?=$openOnly?'1':'0'?>"><?=htmlspecialchars(ucfirst($s))?></a><?php endforeach;?><a href="/admin-operations.php?open=<?=$openOnly?'0':'1'?>"><?=$openOnly?'Show resolved':'Open only'?></a></div>
<section class="dashboard-panel"><div class="dashboard-table-wrap"><table class="dashboard-table"><thead><tr><th>Last seen</th><th>Severity</th><th>Event</th><th>Message</th><th>Count</th><th>Request</th><th></th></tr></thead><tbody>
<?php if(!$rows):?><tr><td colspan="7" class="empty-cell">No operational events in this view.</td></tr><?php endif;?>
<?php foreach($rows as $row):$ctx=json_decode((string)$row['context_json'],true)?:[];?><tr><td><small><?=htmlspecialchars($row['last_seen_at'])?></small></td><td><span class="ops-severity ops-severity-<?=htmlspecialchars($row['severity'])?>"><?=htmlspecialchars($row['severity'])?></span></td><td><strong><?=htmlspecialchars(str_replace('_',' ',$row['event_type']))?></strong></td><td><?=htmlspecialchars($row['message'])?></td><td><?=(int)$row['occurrences']?></td><td><code><?=htmlspecialchars((string)($ctx['request_id']??''))?></code></td><td><?php if(!$row['resolved_at']):?><form method="post"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="id" value="<?=(int)$row['id']?>"><button class="link">Resolve</button></form><?php else:?><small>Resolved <?=htmlspecialchars($row['resolved_at'])?></small><?php endif;?></td></tr><?php endforeach;?>
</tbody></table></div></section>
</main></body></html>