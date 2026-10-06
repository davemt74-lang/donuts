<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{AdminAuditService,BatchTraceabilityService,Database,NotificationService};
require_admin_roles(['super_admin','admin','fulfillment']);

$db=Database::connection();$svc=new BatchTraceabilityService($db);$audit=new AdminAuditService($db);$error='';$notice='';
$id=(int)($_GET['id']??$_POST['id']??0);

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf($_POST['_csrf']??null);
    try{
        $action=(string)($_POST['action']??'');
        if($action==='create'){
            require_admin_roles(['super_admin','admin']);
            $id=$svc->create($_POST,(int)$_SESSION['admin_id']);
            $audit->record((int)$_SESSION['admin_id'],'production_batch_created','production_batch',$id,'Production batch created.',[],['batch_code'=>(string)($_POST['batch_code']??'')]);
            $notice='Production batch created.';
        }elseif($action==='status'){
            require_admin_roles(['super_admin','admin']);
            $before=$svc->batch($id);$to=(string)($_POST['status']??'');$svc->setStatus($id,$to,(string)($_POST['reason']??''));$after=$svc->batch($id);
            $audit->record((int)$_SESSION['admin_id'],'production_batch_status_changed','production_batch',$id,'Production batch status changed.',['status'=>$before['status']],['status'=>$after['status'],'reason'=>$after['recall_reason']]);
            $notice='Batch status updated.';
        }elseif($action==='assign'){
            $orderNumber=strtoupper(trim((string)($_POST['order_number']??'')));
            $q=$db->prepare('SELECT id FROM orders WHERE order_number=?');$q->execute([$orderNumber]);$orderId=(int)$q->fetchColumn();
            if($orderId<1)throw new InvalidArgumentException('Order number was not found.');
            $svc->assignOrder($orderId,$id,(int)$_SESSION['admin_id']);
            $audit->record((int)$_SESSION['admin_id'],'production_batch_assigned','production_batch',$id,'Order assigned to production batch.',[],['order_id'=>$orderId,'order_number'=>$orderNumber]);
            $notice='Order assigned to batch.';
        }elseif($action==='remove_assignment'){
            $orderId=(int)($_POST['order_id']??0);$svc->removeOrderBatch($orderId,$id);
            $audit->record((int)$_SESSION['admin_id'],'production_batch_unassigned','production_batch',$id,'Order removed from production batch.',[],['order_id'=>$orderId]);
            $notice='Order assignment removed.';
        }elseif($action==='notify_recall'){
            require_admin_roles(['super_admin','admin']);
            $batch=$svc->batch($id);$targets=$svc->recallNotificationTargets($id);$notifications=new NotificationService($db);$count=0;
            foreach($targets as $order){
                $subject='Important notice about your Fudge Donuts order '.$order['order_number'];
                $body="We’re contacting you because order {$order['order_number']} was fulfilled from production batch {$batch['batch_code']}.\n\nRecall reason: {$batch['recall_reason']}\n\nPlease do not consume the affected product. Contact Fudge Donuts support for next steps.";
                $notifications->queue((string)$order['email'],$subject,$body,'batch-recall:'.$id.':'.$order['id']);$count++;
            }
            $audit->record((int)$_SESSION['admin_id'],'production_batch_recall_notices','production_batch',$id,$count.' recall notification(s) queued.',[],['orders'=>$count]);
            $notice=$count.' recall notification'.($count===1?'':'s').' queued.';
        }else throw new InvalidArgumentException('Unsupported batch action.');
    }catch(Throwable $e){$error=$e->getMessage();}
}

$status=trim((string)($_GET['status']??''));
try{$batches=$svc->list($status?:null);}catch(Throwable $e){$error=$e->getMessage();$status='';$batches=$svc->list();}
$batch=$id?$svc->batch($id):null;
$flavors=$db->query('SELECT id,name FROM flavors WHERE active=1 ORDER BY sort_order,name')->fetchAll();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Production Batches · Fudge Donuts Admin</title><link rel="stylesheet" href="<?=htmlspecialchars(asset_url('/assets/app.css'))?>"></head><body class="admin-body">
<a class="skip-link" href="#admin-main">Skip to admin content</a>
<header class="admin-topbar"><a class="admin-brand" href="/admin.php">Fudge Donuts <span>Admin</span></a><nav><a href="/admin.php">Dashboard</a><a href="/admin-orders.php">Orders</a><a href="/admin-inventory.php">Inventory</a><a class="active" href="/admin-production-batches.php">Batches</a><a href="/admin-reports.php">Reports</a><a href="/admin-operations.php">Operations</a></nav></header>
<main id="admin-main" tabindex="-1" class="admin-shell">
<div class="admin-page-head"><div><p class="eyebrow">Food safety & traceability</p><h1>Production Batches</h1><p class="admin-welcome">Track production lots from flavor prep through fulfilled customer orders.</p></div></div>
<?php if($error):?><div class="notice error" role="alert"><?=htmlspecialchars($error)?></div><?php endif;?><?php if($notice):?><div class="notice" role="status"><?=htmlspecialchars($notice)?></div><?php endif;?>

<section class="dashboard-grid dashboard-secondary">
<div class="dashboard-panel"><h2>Create production batch</h2><form method="post" class="admin-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="create">
<label>Batch code<input name="batch_code" required placeholder="FD-20261006-A"></label>
<label>Produced at<input type="datetime-local" name="produced_at" required></label>
<label>Best-by date<input type="date" name="best_by_date"></label>
<label>Notes<textarea name="notes" maxlength="5000"></textarea></label>
<h3>Flavor quantities produced</h3>
<div class="batch-flavor-grid"><?php foreach($flavors as $f):?><label><?=htmlspecialchars($f['name'])?><input type="number" min="0" name="flavor_quantity[<?=(int)$f['id']?>]" value="0"></label><?php endforeach;?></div>
<button class="button">Create draft batch</button></form></div>

<div class="dashboard-panel"><h2>Batch states</h2><p><strong>Draft</strong> — recorded but not available for fulfillment.</p><p><strong>Released</strong> — approved for order assignment.</p><p><strong>Hold</strong> — temporarily blocked while investigated.</p><p><strong>Recalled</strong> — affected orders can be identified and customers notified.</p><p><strong>Closed</strong> — historical batch, no new assignments.</p></div>
</section>

<div class="order-filters"><a class="<?=!$status?'active':''?>" href="/admin-production-batches.php">All</a><?php foreach(['draft','released','hold','recalled','closed'] as $s):?><a class="<?=$status===$s?'active':''?>" href="/admin-production-batches.php?status=<?=$s?>"><?=htmlspecialchars(ucfirst($s))?></a><?php endforeach;?></div>

<section class="dashboard-panel"><div class="dashboard-table-wrap"><table class="dashboard-table"><thead><tr><th>Batch</th><th>Produced</th><th>Status</th><th>Units</th><th>Orders</th><th>Best by</th></tr></thead><tbody>
<?php if(!$batches):?><tr><td colspan="6" class="empty-cell">No production batches in this view.</td></tr><?php endif;?>
<?php foreach($batches as $row):?><tr><td><a href="/admin-production-batches.php?id=<?=(int)$row['id']?>"><strong><?=htmlspecialchars($row['batch_code'])?></strong></a></td><td><?=htmlspecialchars($row['produced_at'])?></td><td><?=htmlspecialchars(ucfirst($row['status']))?></td><td><?=(int)$row['total_units']?></td><td><?=(int)$row['order_count']?></td><td><?=htmlspecialchars((string)($row['best_by_date']??''))?></td></tr><?php endforeach;?>
</tbody></table></div></section>

<?php if($batch):?><section class="dashboard-panel batch-detail"><div class="panel-head"><div><p class="eyebrow">Batch detail</p><h2><?=htmlspecialchars($batch['batch_code'])?></h2></div><span class="status"><?=htmlspecialchars(strtoupper($batch['status']))?></span></div>
<div class="dashboard-grid dashboard-secondary"><div><h3>Production</h3><p>Produced <?=htmlspecialchars($batch['produced_at'])?><br><?php if($batch['best_by_date']):?>Best by <?=htmlspecialchars($batch['best_by_date'])?><br><?php endif;?>Created by <?=htmlspecialchars((string)($batch['created_by_email']?:'Unknown'))?></p><p><?=nl2br(htmlspecialchars($batch['notes']))?></p></div><div><h3>Flavor quantities</h3><?php foreach($batch['flavors'] as $f):?><div class="review-total"><span><?=htmlspecialchars($f['name'])?></span><strong><?=(int)$f['quantity_produced']?></strong></div><?php endforeach;?></div></div>

<?php if(admin_has_role(['super_admin','admin']) && $batch['status']!=='closed'):?><form method="post" class="admin-form batch-status-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="status"><label>Change status<select name="status"><?php foreach(['draft','released','hold','recalled','closed'] as $s):?><option value="<?=$s?>" <?=$batch['status']===$s?'selected':''?>><?=htmlspecialchars(ucfirst($s))?></option><?php endforeach;?></select></label><label>Reason / recall instructions<textarea name="reason" maxlength="5000"><?=htmlspecialchars($batch['recall_reason'])?></textarea></label><button class="button secondary">Update batch status</button></form><?php endif;?>

<?php if($batch['status']==='released'):?><form method="post" class="inline-admin"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="assign"><label>Assign fulfilled order<input name="order_number" required placeholder="FD-..."></label><button class="button">Assign order</button></form><?php endif;?>

<div class="panel-head"><h3>Affected / assigned orders</h3><a class="button secondary" href="/admin-batch-affected.csv.php?id=<?=$id?>">Export affected orders</a></div><div class="dashboard-table-wrap"><table class="dashboard-table"><thead><tr><th>Order</th><th>Customer</th><th>Status</th><th>Created</th><th></th></tr></thead><tbody>
<?php if(!$batch['affected_orders']):?><tr><td colspan="5" class="empty-cell">No orders assigned to this batch.</td></tr><?php endif;?>
<?php foreach($batch['affected_orders'] as $o):?><tr><td><a href="/admin-order.php?id=<?=(int)$o['id']?>"><?=htmlspecialchars($o['order_number'])?></a></td><td><?=htmlspecialchars($o['first_name'].' '.$o['last_name'])?><small><?=htmlspecialchars($o['email'])?></small></td><td><?=htmlspecialchars(ucwords(str_replace('_',' ',$o['status'])))?></td><td><?=htmlspecialchars($o['created_at'])?></td><td><?php if(!in_array($o['status'],['shipped','delivered','completed'],true)):?><form method="post"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="remove_assignment"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="order_id" value="<?=(int)$o['id']?>"><button class="link">Remove</button></form><?php endif;?></td></tr><?php endforeach;?>
</tbody></table></div>
<?php if($batch['status']==='recalled'):?><div class="notice error"><strong>Recalled batch</strong><p><?=nl2br(htmlspecialchars($batch['recall_reason']))?></p></div><form method="post"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="notify_recall"><input type="hidden" name="id" value="<?=$id?>"><button class="button">Queue recall notices to affected customers</button></form><?php endif;?>
</section><?php endif;?>
</main></body></html>