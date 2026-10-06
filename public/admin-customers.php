<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{CustomerCrmService,Database};
require_admin_roles(['super_admin','admin']);

$svc=new CustomerCrmService(Database::connection());$q=trim((string)($_GET['q']??''));$error='';
try{$rows=$svc->search($q,150);}catch(Throwable $e){$error=$e->getMessage();$rows=[];}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Customers · Fudge Donuts Admin</title><link rel="stylesheet" href="<?=htmlspecialchars(asset_url('/assets/app.css'))?>"></head><body class="admin-body">
<a class="skip-link" href="#admin-main">Skip to admin content</a>
<header class="admin-topbar"><a class="admin-brand" href="/admin.php">Fudge Donuts <span>Admin</span></a><nav><a href="/admin.php">Dashboard</a><a href="/admin-orders.php">Orders</a><a class="active" href="/admin-customers.php">Customers</a><a href="/admin-support.php">Support</a><a href="/admin-reports.php">Reports</a></nav></header>
<main id="admin-main" tabindex="-1" class="admin-shell"><div class="admin-page-head"><div><p class="eyebrow">Customer relationships</p><h1>Customers</h1><p class="admin-welcome">Registered and guest buyers are unified by normalized email identity.</p></div></div>
<form method="get" class="inline-admin customer-search"><label class="sr-only" for="customer-search">Search customers</label><input id="customer-search" name="q" value="<?=htmlspecialchars($q)?>" placeholder="Email, name, or order number"><button class="button secondary">Search</button><?php if($q!==''):?><a class="button secondary" href="/admin-customers.php">Clear</a><?php endif;?></form>
<?php if($error):?><div class="notice error" role="alert"><?=htmlspecialchars($error)?></div><?php endif;?>
<section class="dashboard-panel"><div class="dashboard-table-wrap"><table class="dashboard-table"><thead><tr><th>Customer</th><th>Orders</th><th>Net lifetime value</th><th>Average order</th><th>Support</th><th>Last order</th><th>Tags</th></tr></thead><tbody>
<?php if(!$rows):?><tr><td colspan="7" class="empty-cell">No matching customers.</td></tr><?php endif;?>
<?php foreach($rows as $row):?><tr><td><a href="/admin-customer.php?email=<?=urlencode($row['email'])?>"><strong><?=htmlspecialchars(trim($row['first_name'].' '.$row['last_name'])?:$row['email'])?></strong></a><small><?=htmlspecialchars($row['email'])?> · <?=$row['registered']?'Account':'Guest'?></small></td><td><?=(int)$row['orders']?></td><td><strong><?=money((int)$row['net_cents'])?></strong><?php if((int)$row['refunded_cents']):?><small><?=money((int)$row['refunded_cents'])?> refunded</small><?php endif;?></td><td><?=money((int)$row['aov_cents'])?></td><td><?=(int)$row['active_support']?></td><td><?=htmlspecialchars($row['last_order_at']?:'—')?></td><td><?=htmlspecialchars(implode(', ',$row['tags'])?:'—')?></td></tr><?php endforeach;?>
</tbody></table></div></section></main></body></html>