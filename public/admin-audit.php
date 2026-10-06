<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{AdminAuditService,Database};

require_admin_roles(['super_admin','admin']);
$svc=new AdminAuditService(Database::connection());
$action=trim((string)($_GET['action']??''));$rows=$svc->recent(250,$action?:null);$stats=$svc->stats();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Audit Trail · Fudge Donuts Admin</title><link rel="stylesheet" href="/assets/app.css"></head><body class="admin-body">
<header class="admin-topbar"><a class="admin-brand" href="/admin.php">Fudge Donuts <span>Admin</span></a><nav><a href="/admin.php">Dashboard</a><a href="/admin-orders.php">Orders</a><a href="/admin-inventory.php">Inventory</a><a href="/admin-shipping.php">Shipping</a><a href="/admin-reports.php">Reports</a><a href="/admin-notifications.php">Email</a><a href="/admin-operations.php">Operations</a><a class="active" href="/admin-audit.php">Audit</a></nav></header>
<main class="admin-shell"><div class="admin-page-head"><div><p class="eyebrow">Accountability</p><h1>Admin Audit Trail</h1><p class="admin-welcome">A durable record of security events and operational changes.</p></div></div>
<section class="dashboard-kpis"><article class="kpi-card"><span>Today</span><strong><?=$stats['today']?></strong><small>Recorded admin events</small></article><article class="kpi-card"><span>Security · 7 days</span><strong><?=$stats['security']?></strong><small>Sign-ins, sign-outs and password activity</small></article><article class="kpi-card"><span>Changes · 7 days</span><strong><?=$stats['changes']?></strong><small>Store and order changes</small></article></section>
<div class="order-filters"><a class="<?=!$action?'active':''?>" href="/admin-audit.php">All</a><?php foreach(['login_success'=>'Logins','order_status_changed'=>'Orders','refund_issued'=>'Refunds','inventory_updated'=>'Inventory','content_updated'=>'Content','admin_account_changed'=>'Admins'] as $k=>$label):?><a class="<?=$action===$k?'active':''?>" href="/admin-audit.php?action=<?=urlencode($k)?>"><?=htmlspecialchars($label)?></a><?php endforeach;?></div>
<section class="dashboard-panel"><div class="dashboard-table-wrap"><table class="dashboard-table"><thead><tr><th>When</th><th>Administrator</th><th>Action</th><th>Target</th><th>Summary</th></tr></thead><tbody><?php if(!$rows):?><tr><td colspan="5" class="empty-cell">No audit events in this view.</td></tr><?php endif;?><?php foreach($rows as $row):?><tr><td><small><?=htmlspecialchars($row['created_at'])?></small></td><td><?=htmlspecialchars($row['actor_email']?:'System / unknown')?></td><td><strong><?=htmlspecialchars(str_replace('_',' ',$row['action']))?></strong></td><td><?=htmlspecialchars(trim($row['entity_type'].' '.$row['entity_id']))?></td><td><?=htmlspecialchars($row['summary'])?></td></tr><?php endforeach;?></tbody></table></div></section>
</main></body></html>
