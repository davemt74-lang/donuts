<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{AdminAuditService,AdminAuthService,Database,NotificationService,SupportService};
require_admin_roles(['super_admin','admin','fulfillment']);

$db=Database::connection();$svc=new SupportService($db);$audit=new AdminAuditService($db);$auth=new AdminAuthService($db);$error='';$notice='';
$id=(int)($_GET['id']??$_POST['id']??0);
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf($_POST['_csrf']??null);
 try{
   $action=(string)($_POST['action']??'');
   if($action==='update'){
     $before=$svc->ticket($id);
     $assigned=(int)($_POST['assigned_admin_id']??0);
     $svc->update($id,(string)$_POST['status'],(string)$_POST['priority'],$assigned?:null);
     $after=$svc->ticket($id);
     $audit->record((int)$_SESSION['admin_id'],'support_ticket_updated','support_ticket',$id,'Support ticket status/priority updated.',$before,$after);
     $notice='Ticket updated.';
   }elseif($action==='reply'){
     $ticket=$svc->reply($id,(int)$_SESSION['admin_id'],(string)($_POST['reply']??''));
     $body="Fudge Donuts support replied to ticket {$ticket['ticket_number']}.\n\n".trim((string)$_POST['reply']);
     (new NotificationService($db))->queue((string)$ticket['email'],'Update on support ticket '.$ticket['ticket_number'],$body,'support-reply:'.$ticket['id'].':'.count($ticket['messages']));
     $audit->record((int)$_SESSION['admin_id'],'support_reply_sent','support_ticket',$id,'Support reply sent.');
     $notice='Reply queued for delivery.';
   }
 }catch(Throwable $e){$error=$e->getMessage();}
}
$status=trim((string)($_GET['status']??''));
try{$rows=$svc->queue($status?:null);}catch(Throwable $e){$error=$e->getMessage();$status='';$rows=$svc->queue();}
$stats=$svc->stats();$ticket=$id?$svc->ticket($id):null;$admins=$auth->all();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Support · Fudge Donuts Admin</title><link rel="stylesheet" href="<?=htmlspecialchars(asset_url('/assets/app.css'))?>"></head><body class="admin-body">
<header class="admin-topbar"><a class="admin-brand" href="/admin.php">Fudge Donuts <span>Admin</span></a><nav><a href="/admin.php">Dashboard</a><a href="/admin-orders.php">Orders</a><a href="/admin-customers.php">Customers</a><a class="active" href="/admin-support.php">Support</a><a href="/admin-reports.php">Reports</a><a href="/admin-operations.php">Operations</a></nav></header>
<main class="admin-shell"><div class="admin-page-head"><div><p class="eyebrow">Customer care</p><h1>Support Queue</h1><p class="admin-welcome">Order-linked customer conversations and service requests.</p></div></div>
<?php if($error):?><div class="notice error"><?=htmlspecialchars($error)?></div><?php endif;?><?php if($notice):?><div class="notice"><?=htmlspecialchars($notice)?></div><?php endif;?>
<section class="dashboard-kpis"><article class="kpi-card"><span>Active</span><strong><?=$stats['active']?></strong><small>Needs attention</small></article><article class="kpi-card"><span>Open</span><strong><?=$stats['open']?></strong><small>New requests</small></article><article class="kpi-card"><span>In progress</span><strong><?=$stats['in_progress']?></strong><small>Being worked</small></article><article class="kpi-card"><span>Waiting customer</span><strong><?=$stats['waiting_customer']?></strong><small>Reply sent</small></article></section>
<div class="order-filters"><a class="<?=!$status?'active':''?>" href="/admin-support.php">All</a><?php foreach(['open','in_progress','waiting_customer','resolved','closed'] as $s):?><a class="<?=$status===$s?'active':''?>" href="/admin-support.php?status=<?=$s?>"><?=htmlspecialchars(ucwords(str_replace('_',' ',$s)))?></a><?php endforeach;?></div>
<div class="support-admin-grid"><section class="dashboard-panel"><div class="dashboard-table-wrap"><table class="dashboard-table"><thead><tr><th>Ticket</th><th>Customer</th><th>Status</th><th>Priority</th><th>Updated</th></tr></thead><tbody><?php if(!$rows):?><tr><td colspan="5" class="empty-cell">No support tickets.</td></tr><?php endif;?><?php foreach($rows as $row):?><tr><td><a href="/admin-support.php?id=<?=(int)$row['id']?>"><strong><?=htmlspecialchars($row['ticket_number'])?></strong></a><small><?=htmlspecialchars($row['subject'])?></small></td><td><?=htmlspecialchars($row['customer_name'])?><small><?=htmlspecialchars($row['email'])?><?php if($row['order_number']):?> · <?=htmlspecialchars($row['order_number'])?><?php endif;?></small></td><td><?=htmlspecialchars(ucwords(str_replace('_',' ',$row['status'])))?></td><td><?=htmlspecialchars(ucfirst($row['priority']))?></td><td><?=htmlspecialchars($row['updated_at'])?></td></tr><?php endforeach;?></tbody></table></div></section>
<?php if($ticket):?><aside class="dashboard-panel support-ticket-panel"><p class="eyebrow"><?=htmlspecialchars($ticket['ticket_number'])?></p><h2><?=htmlspecialchars($ticket['subject'])?></h2><p><?=htmlspecialchars($ticket['customer_name'])?><br><a href="mailto:<?=htmlspecialchars($ticket['email'])?>"><?=htmlspecialchars($ticket['email'])?></a><?php if($ticket['order_number']):?><br><a href="/admin-order.php?id=<?=(int)$ticket['order_id']?>"><?=htmlspecialchars($ticket['order_number'])?></a><?php endif;?></p>
<div class="support-thread"><?php foreach($ticket['messages'] as $m):?><article class="support-message support-message-<?=htmlspecialchars($m['author_type'])?>"><strong><?=htmlspecialchars($m['author_type']==='admin'?($m['admin_email']?:'Admin'):'Customer')?></strong><small><?=htmlspecialchars($m['created_at'])?></small><p><?=nl2br(htmlspecialchars($m['body']))?></p></article><?php endforeach;?></div>
<form method="post" class="admin-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="reply"><label>Reply<textarea name="reply" required maxlength="5000" rows="5"></textarea></label><button class="button">Send reply</button></form>
<form method="post" class="admin-form support-controls"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="update"><label>Status<select name="status"><?php foreach(['open','in_progress','waiting_customer','resolved','closed'] as $s):?><option value="<?=$s?>" <?=$ticket['status']===$s?'selected':''?>><?=htmlspecialchars(ucwords(str_replace('_',' ',$s)))?></option><?php endforeach;?></select></label><label>Priority<select name="priority"><?php foreach(['normal','high','urgent'] as $p):?><option value="<?=$p?>" <?=$ticket['priority']===$p?'selected':''?>><?=htmlspecialchars(ucfirst($p))?></option><?php endforeach;?></select></label><label>Assigned<select name="assigned_admin_id"><option value="0">Unassigned</option><?php foreach($admins as $a):if(!(int)$a['active'])continue;?><option value="<?=(int)$a['id']?>" <?=(int)$ticket['assigned_admin_id']===(int)$a['id']?'selected':''?>><?=htmlspecialchars($a['email'])?></option><?php endforeach;?></select></label><button class="button secondary">Update ticket</button></form></aside><?php endif;?></div>
</main></body></html>