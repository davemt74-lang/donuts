<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{CheckoutRecoveryService,Database};
require_admin_roles(['super_admin','admin']);

$db=Database::connection();$svc=new CheckoutRecoveryService($db,(string)env('APP_KEY',''),(string)env('APP_URL','http://127.0.0.1:8080'),(int)env('CHECKOUT_RECOVERY_DAYS','7'));
$stats=$svc->stats();$rows=$svc->recent(250);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Checkout Recovery · Fudge Donuts Admin</title><link rel="stylesheet" href="<?=htmlspecialchars(asset_url('/assets/app.css'))?>"></head><body class="admin-body">
<a class="skip-link" href="#admin-main">Skip to admin content</a>
<header class="admin-topbar"><a class="admin-brand" href="/admin.php">Fudge Donuts <span>Admin</span></a><nav><a href="/admin.php">Dashboard</a><a href="/admin-orders.php">Orders</a><a href="/admin-customers.php">Customers</a><a href="/admin-marketing.php">Marketing</a><a class="active" href="/admin-checkout-recovery.php">Recovery</a><a href="/admin-operations.php">Operations</a></nav></header>
<main id="admin-main" tabindex="-1" class="admin-shell">
<div class="admin-page-head"><div><p class="eyebrow">Lifecycle messaging</p><h1>Checkout Recovery</h1><p class="admin-welcome">Privacy-minimal abandoned checkout snapshots and consent-safe reminder delivery.</p></div></div>
<section class="dashboard-kpis">
<article class="kpi-card"><span>Active</span><strong><?=(int)$stats['active']?></strong><small>Eligible saved checkouts</small></article>
<article class="kpi-card"><span>Recovered</span><strong><?=(int)$stats['recovered']?></strong><small>Returned through signed link</small></article>
<article class="kpi-card"><span>Converted</span><strong><?=(int)$stats['converted']?></strong><small>Completed orders</small></article>
<article class="kpi-card"><span>Reminders</span><strong><?=(int)$stats['reminders']?></strong><small>Sent with current consent</small></article>
</section>
<div class="allergen-callout"><strong>Consent rule</strong><p>Recovery snapshots are created for checkout continuity, but reminder email is sent only when the checkout email is currently explicitly subscribed to marketing. Every reminder includes an unsubscribe link. No shipping address is stored in this recovery table.</p></div>
<section class="dashboard-panel"><div class="dashboard-table-wrap"><table class="dashboard-table"><thead><tr><th>Email</th><th>Status</th><th>Created</th><th>Reminder</th><th>Recovered</th><th>Expires</th><th>Order</th></tr></thead><tbody>
<?php if(!$rows):?><tr><td colspan="7" class="empty-cell">No checkout recovery snapshots yet.</td></tr><?php endif;?>
<?php foreach($rows as $row):?><tr>
<td><?=htmlspecialchars($row['email'])?></td>
<td><strong><?=htmlspecialchars(ucfirst($row['status']))?></strong></td>
<td><?=htmlspecialchars($row['created_at'])?></td>
<td><?=htmlspecialchars((string)($row['reminder_sent_at']??'—'))?></td>
<td><?=htmlspecialchars((string)($row['recovered_at']??'—'))?></td>
<td><?=htmlspecialchars($row['expires_at'])?></td>
<td><?php if(!empty($row['converted_order_id'])):?><a href="/admin-order.php?id=<?=(int)$row['converted_order_id']?>">#<?=(int)$row['converted_order_id']?></a><?php elseif(!empty($row['linked_order_id'])):?><a href="/admin-order.php?id=<?=(int)$row['linked_order_id']?>">Pending #<?=(int)$row['linked_order_id']?></a><?php else:?>—<?php endif;?></td>
</tr><?php endforeach;?>
</tbody></table></div></section>
</main></body></html>