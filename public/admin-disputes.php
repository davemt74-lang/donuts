<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{Database,DisputeService};
require_admin_roles(['super_admin','admin']);

$db=Database::connection();$svc=new DisputeService($db);$status=trim((string)($_GET['status']??''));$error='';
try{$rows=$svc->recent($status?:null);}catch(Throwable $e){$error=$e->getMessage();$status='';$rows=$svc->recent();}
$stats=$svc->stats();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Payment Disputes · Fudge Donuts Admin</title><link rel="stylesheet" href="<?=htmlspecialchars(asset_url('/assets/app.css'))?>"></head><body class="admin-body">
<a class="skip-link" href="#admin-main">Skip to admin content</a>
<header class="admin-topbar"><a class="admin-brand" href="/admin.php">Fudge Donuts <span>Admin</span></a><nav><a href="/admin.php">Dashboard</a><a href="/admin-orders.php">Orders</a><a href="/admin-disputes.php" class="active">Disputes</a><a href="/admin-reports.php">Reports</a><a href="/admin-operations.php">Operations</a></nav></header>
<main id="admin-main" tabindex="-1" class="admin-shell">
<div class="admin-page-head"><div><p class="eyebrow">Payment risk</p><h1>Stripe Disputes</h1><p class="admin-welcome">Chargebacks and payment disputes requiring operational attention.</p></div></div>
<?php if($error):?><div class="notice error" role="alert"><?=htmlspecialchars($error)?></div><?php endif;?>
<section class="dashboard-kpis">
<article class="kpi-card <?=($stats['open']>0?'kpi-alert':'')?>"><span>Open</span><strong><?=$stats['open']?></strong><small><?=money((int)$stats['open_amount_cents'])?> at risk</small></article>
<article class="kpi-card"><span>Lost</span><strong><?=$stats['lost']?></strong><small>Closed against the store</small></article>
<article class="kpi-card"><span>Won</span><strong><?=$stats['won']?></strong><small>Resolved in store favor</small></article>
<article class="kpi-card"><span>Total</span><strong><?=$stats['total']?></strong><small>Recorded disputes</small></article>
</section>
<div class="order-filters"><a class="<?=!$status?'active':''?>" href="/admin-disputes.php">All</a><?php foreach(['needs_response','under_review','won','lost'] as $s):?><a class="<?=$status===$s?'active':''?>" href="/admin-disputes.php?status=<?=urlencode($s)?>"><?=htmlspecialchars(ucwords(str_replace('_',' ',$s)))?></a><?php endforeach;?></div>
<section class="dashboard-panel"><div class="dashboard-table-wrap"><table class="dashboard-table"><thead><tr><th>Dispute</th><th>Order / gift card</th><th>Status</th><th>Amount</th><th>Reason</th><th>Evidence due</th><th>Updated</th></tr></thead><tbody>
<?php if(!$rows):?><tr><td colspan="7" class="empty-cell">No disputes in this view.</td></tr><?php endif;?>
<?php foreach($rows as $row):?><tr>
<td><strong><?=htmlspecialchars($row['stripe_dispute_id'])?></strong></td>
<td><?php if($row['order_id']):?><a href="/admin-order.php?id=<?=(int)$row['order_id']?>"><?=htmlspecialchars($row['order_number'])?></a><?php elseif($row['gift_card_purchase_id']):?>Gift card purchase #<?=(int)$row['gift_card_purchase_id']?><br><small><?=htmlspecialchars((string)$row['gift_recipient_email'])?></small><?php else:?><span class="muted">Unmatched payment</span><?php endif;?></td>
<td><span class="ops-severity <?=in_array($row['status'],['needs_response','warning_needs_response'],true)?'ops-severity-error':($row['status']==='lost'?'ops-severity-critical':($row['status']==='won'?'ops-severity-info':'ops-severity-warning'))?>"><?=htmlspecialchars(str_replace('_',' ',$row['status']))?></span></td>
<td><?=money((int)$row['amount_cents'])?> <small><?=htmlspecialchars(strtoupper($row['currency']))?></small></td>
<td><?=htmlspecialchars($row['reason']?:'Not specified')?></td>
<td><?=htmlspecialchars((string)($row['evidence_due_at']??''))?></td>
<td><?=htmlspecialchars($row['updated_at'])?></td>
</tr><?php endforeach;?>
</tbody></table></div></section>
<div class="allergen-callout"><strong>Evidence submission</strong><p>Evidence and acceptance decisions remain in Stripe Dashboard. This queue protects Fudge Donuts operations and links Stripe dispute state back to orders and stored-value gift cards.</p></div>
</main></body></html>