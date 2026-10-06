<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{AdminAuditService,BatchTraceabilityService,CatalogRepository,Database,NotificationService};
require_admin_roles(['super_admin','admin','fulfillment']);
$db=Database::connection();$svc=new BatchTraceabilityService($db);$audit=new AdminAuditService($db);$catalog=new CatalogRepository($db);$error='';$notice='';
$id=(int)($_GET['id']??$_POST['batch_id']??0);
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf($_POST['_csrf']??null);
 try{
  $action=(string)($_POST['action']??'');
  if($action==='create'){
    $id=$svc->createBatch($_POST,(int)$_SESSION['admin_id']);
    $audit->record((int)$_SESSION['admin_id'],'production_batch_created','production_batch',$id,'Production batch created.',[],$_POST);
    $notice='Production batch created.';
  }elseif($action==='hold'){
    $hold=!empty($_POST['hold']);$svc->setHold($id,$hold);
    $audit->record((int)$_SESSION['admin_id'],'production_batch_hold_changed','production_batch',$id,$hold?'Production batch placed on hold.':'Production batch released from hold.');
    $notice=$hold?'Batch placed on hold.':'Batch released from hold.';
  }elseif($action==='recall'){
    require_admin_roles(['super_admin','admin']);
    $affected=$svc->recall($id,(string)($_POST['reason']??''),(int)$_SESSION['admin_id']);
    $audit->record((int)$_SESSION['admin_id'],'production_batch_recalled','production_batch',$id,'Production batch recalled.',[],['affected_orders'=>count($affected)]);
    $notice='Batch recalled. '.count($affected).' affected order'.(count($affected)===1?'':'s').' identified.';
  }elseif($action==='notify_recall'){
    require_admin_roles(['super_admin','admin']);
    $batch=$svc->batch($id);if($batch['status']!=='recalled')throw new InvalidArgumentException('Recall notices can only be sent for recalled batches.');
    $affected=$svc->affectedOrders($id);$n=new NotificationService($db);$queued=0;$failed=0;
    foreach($affected as $o){
      $body="Important product notice for order {$o['order_number']}.\n\nA production batch used in this order has been recalled. Reason: {$batch['recall_reason']}\n\nPlease contact Fudge Donuts support for assistance.";
      $key='batch-recall:'.$id.':'.$o['id'];
      try{
        $n->queue((string)$o['email'],'Important notice about Fudge Donuts order '.$o['order_number'],$body,$key);
        $q=$db->prepare('SELECT id,status FROM notification_outbox WHERE idempotency_key=?');$q->execute([$key]);$msg=$q->fetch();
        if($msg && $msg['status']==='failed')$n->retry((int)$msg['id']);
        $queued++;
      }catch(Throwable){$failed++;}
    }
    $audit->record((int)$_SESSION['admin_id'],'production_batch_recall_notices','production_batch',$id,'Recall notices queued.',[],['affected_orders'=>count($affected),'queued'=>$queued,'failed'=>$failed]);
    $notice=$queued.' recall notice'.($queued===1?'':'s').' queued'.($failed?' · '.$failed.' failed to queue':'').'.';
  }else throw new InvalidArgumentException('Unsupported batch action.');
 }catch(Throwable $e){$error=$e->getMessage();}
}
$batches=$svc->batches();$summary=$svc->summary();$flavors=$catalog->flavors(false);$current=$id?$svc->batch($id):null;$affected=$current?$svc->affectedOrders($id):[];
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Batch Traceability · Fudge Donuts Admin</title><link rel="stylesheet" href="<?=htmlspecialchars(asset_url('/assets/app.css'))?>"></head><body class="admin-body">
<header class="admin-topbar"><a class="admin-brand" href="/admin.php">Fudge Donuts <span>Admin</span></a><nav><a href="/admin.php">Dashboard</a><a href="/admin-inventory.php">Inventory</a><a href="/admin-ingredient-lots.php">Ingredients</a><a class="active" href="/admin-batches.php">Batches</a><a href="/admin-orders.php">Orders</a><a href="/admin-support.php">Support</a></nav></header>
<main class="admin-shell"><div class="admin-page-head"><div><p class="eyebrow">Traceability</p><h1>Production Batches</h1><p class="admin-welcome">Track production lots through fulfillment and identify affected orders during a recall.</p></div></div>
<?php if($error):?><div class="notice error" role="alert"><?=htmlspecialchars($error)?></div><?php endif;?><?php if($notice):?><div class="notice" role="status"><?=htmlspecialchars($notice)?></div><?php endif;?>
<section class="dashboard-kpis"><article class="kpi-card"><span>Active</span><strong><?=$summary['active']?></strong></article><article class="kpi-card"><span>Depleted</span><strong><?=$summary['depleted']?></strong></article><article class="kpi-card <?=$summary['hold']?'kpi-alert':''?>"><span>Hold</span><strong><?=$summary['hold']?></strong></article><article class="kpi-card <?=$summary['recalled']||$summary['expired_active']?'kpi-alert':''?>"><span>Recalled / expired active</span><strong><?=$summary['recalled']?> / <?=$summary['expired_active']?></strong></article></section>
<section class="dashboard-grid dashboard-secondary"><div class="dashboard-panel"><h2>Batch ledger</h2><div class="dashboard-table-wrap"><table class="dashboard-table"><thead><tr><th>Batch</th><th>Flavor</th><th>Produced</th><th>Best by</th><th>Remaining</th><th>Status</th></tr></thead><tbody><?php foreach($batches as $b):?><tr><td><a href="/admin-batches.php?id=<?=(int)$b['id']?>"><strong><?=htmlspecialchars($b['batch_code'])?></strong></a></td><td><?=htmlspecialchars($b['flavor_name'])?></td><td><?=htmlspecialchars($b['produced_at'])?></td><td><?=htmlspecialchars((string)$b['best_by_date'])?></td><td><?=(int)$b['quantity_remaining']?> / <?=(int)$b['quantity_produced']?></td><td><?=htmlspecialchars($b['status'])?></td></tr><?php endforeach;?></tbody></table></div></div>
<div class="dashboard-panel"><h2>Create batch</h2><form method="post" class="admin-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="create"><label>Batch code<input name="batch_code" required placeholder="LOT-2026-1006-A"></label><label>Flavor<select name="flavor_id" required><?php foreach($flavors as $f):?><option value="<?=(int)$f['id']?>"><?=htmlspecialchars($f['name'])?></option><?php endforeach;?></select></label><label>Produced at<input type="datetime-local" name="produced_at" required></label><label>Best-by date<input type="date" name="best_by_date"></label><label>Quantity produced<input type="number" min="1" name="quantity_produced" required></label><label>Notes<textarea name="notes"></textarea></label><button class="button">Create batch</button></form></div></section>
<?php if($current):?><section class="dashboard-panel <?=$current['status']==='recalled'?'alert-panel':''?>"><div class="panel-head"><div><p class="eyebrow"><?=htmlspecialchars($current['batch_code'])?></p><h2><?=htmlspecialchars($current['flavor_name'])?></h2></div><a class="button secondary" href="/admin-batch-affected.csv.php?id=<?=$id?>">Export affected orders</a></div><p>Status: <strong><?=htmlspecialchars($current['status'])?></strong> · Remaining <?=(int)$current['quantity_remaining']?> of <?=(int)$current['quantity_produced']?></p><?php if($current['recall_reason']):?><div class="notice error"><strong>Recall reason:</strong> <?=htmlspecialchars($current['recall_reason'])?></div><?php endif;?><h3>Affected orders</h3><div class="dashboard-table-wrap"><table class="dashboard-table"><thead><tr><th>Order</th><th>Customer</th><th>Status</th><th>Qty</th></tr></thead><tbody><?php if(!$affected):?><tr><td colspan="4">No fulfilled orders currently reference this batch.</td></tr><?php endif;?><?php foreach($affected as $o):?><tr><td><a href="/admin-order.php?id=<?=(int)$o['id']?>"><?=htmlspecialchars($o['order_number'])?></a></td><td><?=htmlspecialchars($o['first_name'].' '.$o['last_name'])?><small><?=htmlspecialchars($o['email'])?></small></td><td><?=htmlspecialchars($o['status'])?></td><td><?=(int)$o['quantity']?></td></tr><?php endforeach;?></tbody></table></div><?php if(in_array($current['status'],['active','hold'],true)):?><form method="post" class="actions"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="hold"><input type="hidden" name="batch_id" value="<?=$id?>"><input type="hidden" name="hold" value="<?=$current['status']==='hold'?'0':'1'?>"><button class="button secondary"><?=$current['status']==='hold'?'Release hold':'Place on hold'?></button></form><?php endif;?>
<?php if($current['status']!=='recalled' && admin_has_role(['super_admin','admin'])):?><form method="post" class="admin-form recall-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="recall"><input type="hidden" name="batch_id" value="<?=$id?>"><label>Recall reason<textarea name="reason" required></textarea></label><button class="button">Recall batch</button></form><?php endif;?><?php if($current['status']==='recalled' && admin_has_role(['super_admin','admin'])):?><form method="post" class="actions"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="notify_recall"><input type="hidden" name="batch_id" value="<?=$id?>"><button class="button">Queue / retry failed recall notices</button></form><?php endif;?></section><?php endif;?>
</main></body></html>