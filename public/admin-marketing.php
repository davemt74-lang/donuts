<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{Database,MarketingConsentService};
require_admin_roles(['super_admin','admin']);

$svc=new MarketingConsentService(
    Database::connection(),
    (string)env('APP_KEY',''),
    (string)env('APP_URL','http://127.0.0.1:8080')
);
$error='';$status=trim((string)($_GET['status']??''));
try{$rows=$svc->subscribers($status?:null,500);}catch(Throwable $e){$error=$e->getMessage();$status='';$rows=$svc->subscribers();}
$stats=$svc->stats();

if(($_GET['export']??'')==='csv'){
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="fudge-donuts-marketing-list.csv"');
    $out=fopen('php://output','wb');fputcsv($out,['email','status','created_at','updated_at']);
    foreach($rows as $r)fputcsv($out,[$r['email'],$r['status'],$r['created_at'],$r['updated_at']]);
    fclose($out);exit;
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Marketing · Fudge Donuts Admin</title><link rel="stylesheet" href="/assets/app.css"></head><body class="admin-body">
<header class="admin-topbar"><a class="admin-brand" href="/admin.php">Fudge Donuts <span>Admin</span></a><nav><a href="/admin.php">Dashboard</a><a href="/admin-flavors.php">Flavors</a><a href="/admin-packs.php">Packs</a><a href="/admin-orders.php">Orders</a><a href="/admin-inventory.php">Inventory</a><a href="/admin-promotions.php">Promotions</a><a href="/admin-content.php">Content</a><a href="/admin-reports.php">Reports</a><a href="/admin-notifications.php">Email</a><a href="/admin-audit.php">Audit</a><a href="/admin-operations.php">Operations</a><a class="active" href="/admin-marketing.php">Marketing</a></nav></header>
<main class="admin-shell"><div class="admin-page-head"><div><p class="eyebrow">Audience</p><h1>Marketing List</h1><p class="admin-welcome">Consent-aware subscribers and suppressions.</p></div><a class="button secondary" href="/admin-marketing.php?<?=http_build_query(['status'=>$status,'export'=>'csv'])?>">Export CSV</a></div>
<?php if($error):?><div class="notice error"><?=htmlspecialchars($error)?></div><?php endif;?>
<section class="dashboard-kpis"><article class="kpi-card"><span>Subscribed</span><strong><?=(int)$stats['subscribed']?></strong><small>Eligible for marketing</small></article><article class="kpi-card"><span>Unsubscribed</span><strong><?=(int)$stats['unsubscribed']?></strong><small>Suppressed</small></article><article class="kpi-card"><span>Total records</span><strong><?=(int)$stats['total']?></strong><small>Known marketing addresses</small></article></section>
<div class="order-filters marketing-filters"><a class="<?=!$status?'active':''?>" href="/admin-marketing.php">All</a><a class="<?=$status==='subscribed'?'active':''?>" href="/admin-marketing.php?status=subscribed">Subscribed</a><a class="<?=$status==='unsubscribed'?'active':''?>" href="/admin-marketing.php?status=unsubscribed">Unsubscribed</a></div>
<section class="dashboard-panel"><div class="dashboard-table-wrap"><table class="dashboard-table"><thead><tr><th>Email</th><th>Status</th><th>Joined</th><th>Last updated</th></tr></thead><tbody><?php if(!$rows):?><tr><td colspan="4" class="empty-cell">No subscribers in this view.</td></tr><?php endif;?><?php foreach($rows as $r):?><tr><td><?=htmlspecialchars($r['email'])?></td><td><span class="marketing-status marketing-status-<?=htmlspecialchars($r['status'])?>"><?=htmlspecialchars($r['status'])?></span></td><td><?=htmlspecialchars($r['created_at'])?></td><td><?=htmlspecialchars($r['updated_at'])?></td></tr><?php endforeach;?></tbody></table></div></section>
</main></body></html>