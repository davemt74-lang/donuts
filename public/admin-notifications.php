<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{Database,NotificationService};
require_admin_roles(['super_admin','admin']);

$db=Database::connection();$svc=new NotificationService($db);$error='';$notice='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf($_POST['_csrf']??null);
    try{
        $action=(string)($_POST['action']??'');
        if($action==='retry'){$svc->retry((int)($_POST['id']??0));$notice='Email queued for retry.';}
        elseif($action==='retry_all'){$count=$svc->retryAllFailed();$notice=$count.' failed email'.($count===1?'':'s').' queued for retry.';}
        else throw new InvalidArgumentException('Unsupported notification action.');
    }catch(Throwable $e){$error=$e->getMessage();}
}
$status=trim((string)($_GET['status']??''));
try{$rows=$svc->recent(150,$status?:null);}catch(Throwable $e){$error=$e->getMessage();$status='';$rows=$svc->recent();}
$stats=$svc->stats();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Email Delivery · Fudge Donuts Admin</title><link rel="stylesheet" href="/assets/app.css"></head><body class="admin-body">
<header class="admin-topbar"><a class="admin-brand" href="/admin.php">Fudge Donuts <span>Admin</span></a><nav><a href="/admin.php">Dashboard</a><a href="/admin-flavors.php">Flavors</a><a href="/admin-packs.php">Packs</a><a href="/admin-orders.php">Orders</a><a href="/admin-inventory.php">Inventory</a><a href="/admin-shipping.php">Shipping</a><a href="/admin-promotions.php">Promotions</a><a href="/admin-content.php">Content</a><a href="/admin-reports.php">Reports</a><a class="active" href="/admin-notifications.php">Email</a></nav></header>
<main class="admin-shell">
<div class="admin-page-head"><div><p class="eyebrow">Delivery operations</p><h1>Transactional Email</h1><p class="admin-welcome">Monitor confirmations, password resets and fulfillment messages.</p></div><?php if($stats['failed']>0):?><form method="post"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="retry_all"><button class="button">Retry all failed</button></form><?php endif;?></div>
<?php if($error):?><div class="notice error"><?=htmlspecialchars($error)?></div><?php endif;?><?php if($notice):?><div class="notice"><?=htmlspecialchars($notice)?></div><?php endif;?>
<section class="dashboard-kpis email-kpis">
<article class="kpi-card"><span>Pending</span><strong><?=(int)$stats['pending']?></strong><small>Awaiting delivery</small></article>
<article class="kpi-card"><span>Sent</span><strong><?=(int)$stats['sent']?></strong><small>Delivered to transport</small></article>
<article class="kpi-card <?=((int)$stats['failed']>0?'kpi-alert':'')?>"><span>Failed</span><strong><?=(int)$stats['failed']?></strong><small>Needs attention</small></article>
<article class="kpi-card"><span>Total</span><strong><?=(int)$stats['total']?></strong><small>Outbox messages</small></article>
</section>
<div class="order-filters email-filters"><a class="<?=!$status?'active':''?>" href="/admin-notifications.php">All</a><?php foreach(['pending'=>'Pending','failed'=>'Failed','sent'=>'Sent'] as $s=>$label):?><a class="<?=$status===$s?'active':''?>" href="/admin-notifications.php?status=<?=$s?>"><?=htmlspecialchars($label)?></a><?php endforeach;?></div>
<section class="dashboard-panel"><div class="dashboard-table-wrap"><table class="dashboard-table"><thead><tr><th>Recipient</th><th>Subject</th><th>Status</th><th>Attempts</th><th>Created / sent</th><th>Error</th><th></th></tr></thead><tbody>
<?php if(!$rows):?><tr><td colspan="7" class="empty-cell">No email messages in this view.</td></tr><?php endif;?>
<?php foreach($rows as $m):?><tr><td><?=htmlspecialchars($m['recipient'])?></td><td><strong><?=htmlspecialchars($m['subject'])?></strong></td><td><span class="email-status email-status-<?=htmlspecialchars($m['status'])?>"><?=htmlspecialchars($m['status'])?></span></td><td><?=(int)$m['attempts']?></td><td><small><?=htmlspecialchars($m['created_at'])?></small><?php if($m['sent_at']):?><br><small>Sent <?=htmlspecialchars($m['sent_at'])?></small><?php endif;?></td><td class="email-error"><?=htmlspecialchars(mb_strimwidth((string)$m['last_error'],0,120,'…'))?></td><td><?php if($m['status']==='failed'):?><form method="post"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="retry"><input type="hidden" name="id" value="<?=(int)$m['id']?>"><button class="link">Retry</button></form><?php endif;?></td></tr><?php endforeach;?>
</tbody></table></div></section>
</main></body></html>