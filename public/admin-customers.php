<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{CustomerDirectoryService,Database};
require_admin_roles(['super_admin','admin']);

$db=Database::connection();$svc=new CustomerDirectoryService($db);
$q=trim((string)($_GET['q']??''));$key=trim((string)($_GET['customer']??''));$error='';
try{$rows=$svc->customers($q,250);$stats=$svc->stats();$profile=$key!==''?$svc->profile($key):null;}
catch(Throwable $e){$error=$e->getMessage();$rows=[];$stats=['customers'=>0,'accounts'=>0,'guests'=>0,'repeat'=>0,'lifetime_cents'=>0];$profile=null;}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Customers · Fudge Donuts Admin</title><link rel="stylesheet" href="<?=htmlspecialchars(asset_url('/assets/app.css'))?>"></head><body class="admin-body">
<a class="skip-link" href="#admin-main">Skip to admin content</a>
<header class="admin-topbar"><a class="admin-brand" href="/admin.php">Fudge Donuts <span>Admin</span></a><nav><a href="/admin.php">Dashboard</a><a class="active" href="/admin-customers.php">Customers</a><a href="/admin-orders.php">Orders</a><a href="/admin-support.php">Support</a><a href="/admin-marketing.php">Marketing</a><a href="/admin-reports.php">Reports</a><a href="/admin-audit.php">Audit</a></nav></header>
<main id="admin-main" tabindex="-1" class="admin-shell">
<div class="admin-page-head"><div><p class="eyebrow">Customer operations</p><h1>Customers</h1><p class="admin-welcome">Accounts and guest purchasers, aggregated from canonical store activity.</p></div></div>
<?php if($error):?><div class="notice error" role="alert"><?=htmlspecialchars($error)?></div><?php endif;?>
<section class="dashboard-kpis">
<article class="kpi-card"><span>Known customers</span><strong><?=(int)$stats['customers']?></strong><small>Account + guest identities</small></article>
<article class="kpi-card"><span>Accounts</span><strong><?=(int)$stats['accounts']?></strong><small>Registered customers</small></article>
<article class="kpi-card"><span>Repeat customers</span><strong><?=(int)$stats['repeat']?></strong><small>More than one order</small></article>
<article class="kpi-card"><span>Customer revenue</span><strong><?=money((int)$stats['lifetime_cents'])?></strong><small>Current non-refunded order value</small></article>
</section>

<form method="get" class="customer-search"><label for="customer-search">Search customers</label><div><input id="customer-search" name="q" value="<?=htmlspecialchars($q)?>" placeholder="Name or email"><button class="button secondary">Search</button><?php if($q!==''):?><a class="button secondary" href="/admin-customers.php">Clear</a><?php endif;?></div></form>

<div class="customer-admin-grid">
<section class="dashboard-panel"><div class="dashboard-table-wrap"><table class="dashboard-table"><thead><tr><th>Customer</th><th>Type</th><th>Orders</th><th>Lifetime value</th><th>Last order</th><th>Support</th><th>Marketing</th></tr></thead><tbody>
<?php if(!$rows):?><tr><td colspan="7" class="empty-cell">No customers match this view.</td></tr><?php endif;?>
<?php foreach($rows as $row):?><tr>
<td><a href="/admin-customers.php?<?=http_build_query(['q'=>$q,'customer'=>$row['customer_key']])?>"><strong><?=htmlspecialchars($row['display_name'])?></strong></a><small><?=htmlspecialchars($row['email'])?></small></td>
<td><span class="status"><?=htmlspecialchars($row['customer_type'])?></span></td>
<td><?=(int)$row['order_count']?></td>
<td><strong><?=money((int)$row['lifetime_cents'])?></strong></td>
<td><?=htmlspecialchars((string)($row['last_order_at']??'—'))?></td>
<td><?=(int)$row['active_support']?> active</td>
<td><?=htmlspecialchars($row['marketing_status'])?></td>
</tr><?php endforeach;?>
</tbody></table></div></section>

<?php if($profile):?><aside class="dashboard-panel customer-profile-panel">
<div class="panel-head"><div><p class="eyebrow"><?=htmlspecialchars($profile['customer_type'])?> customer</p><h2><?=htmlspecialchars($profile['display_name'])?></h2><p><?=htmlspecialchars($profile['email'])?></p></div></div>
<div class="customer-profile-metrics"><div><span>Orders</span><strong><?=(int)$profile['order_count']?></strong></div><div><span>Revenue</span><strong><?=money((int)$profile['lifetime_cents'])?></strong></div><div><span>Last order</span><strong><?=htmlspecialchars((string)($profile['last_order_at']??'—'))?></strong></div></div>

<section><h3>Orders</h3><div class="customer-mini-list"><?php if(!$profile['orders']):?><p class="muted">No orders.</p><?php endif;?><?php foreach($profile['orders'] as $o):?><a href="/admin-order.php?id=<?=(int)$o['id']?>"><span><strong><?=htmlspecialchars($o['order_number'])?></strong><small><?=htmlspecialchars($o['created_at'])?> · <?=htmlspecialchars(str_replace('_',' ',$o['status']))?></small></span><b><?=money((int)$o['total_cents'])?></b></a><?php endforeach;?></div></section>

<section><h3>Saved addresses</h3><?php if(!$profile['addresses']):?><p class="muted">No saved account addresses.</p><?php endif;?><?php foreach($profile['addresses'] as $a):?><address class="customer-address"><strong><?=htmlspecialchars($a['label'])?><?=(int)$a['is_default']?' · Default':''?></strong><br><?=htmlspecialchars($a['first_name'].' '.$a['last_name'])?><br><?=htmlspecialchars($a['line1'])?><?php if($a['line2']):?><br><?=htmlspecialchars($a['line2'])?><?php endif;?><br><?=htmlspecialchars($a['city'].', '.$a['region'].' '.$a['postal_code'])?></address><?php endforeach;?></section>

<section><h3>Support</h3><div class="customer-mini-list"><?php if(!$profile['support']):?><p class="muted">No support tickets.</p><?php endif;?><?php foreach($profile['support'] as $s):?><a href="/admin-support.php?id=<?=(int)$s['id']?>"><span><strong><?=htmlspecialchars($s['ticket_number'])?></strong><small><?=htmlspecialchars($s['subject'])?></small></span><b><?=htmlspecialchars(str_replace('_',' ',$s['status']))?></b></a><?php endforeach;?></div></section>

<section><h3>Marketing & privacy</h3><div class="customer-facts"><div><span>Marketing</span><strong><?=htmlspecialchars($profile['marketing']['status']??'No record')?></strong></div><div><span>Privacy events</span><strong><?=count($profile['privacy'])?></strong></div></div><?php if($profile['privacy']):?><div class="customer-privacy-list"><?php foreach(array_slice($profile['privacy'],0,8) as $p):?><div><strong><?=htmlspecialchars(str_replace('_',' ',$p['action']))?></strong><small><?=htmlspecialchars($p['created_at'])?></small></div><?php endforeach;?></div><?php endif;?></section>
</aside><?php endif;?>
</div>
</main></body></html>