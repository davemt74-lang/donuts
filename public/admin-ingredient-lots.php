<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{AdminAuditService,BatchTraceabilityService,Database,IngredientTraceabilityService,NotificationService};
require_admin_roles(['super_admin','admin','fulfillment']);

$db=Database::connection();$svc=new IngredientTraceabilityService($db);$batchSvc=new BatchTraceabilityService($db);$audit=new AdminAuditService($db);$error='';$notice='';
$id=(int)($_GET['id']??$_POST['lot_id']??0);

if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf($_POST['_csrf']??null);
 try{
  $action=(string)($_POST['action']??'');
  if($action==='create'){
    $id=$svc->createLot($_POST,(int)$_SESSION['admin_id']);
    $audit->record((int)$_SESSION['admin_id'],'ingredient_lot_created','ingredient_lot',$id,'Ingredient supplier lot created.',[],['ingredient_name'=>(string)($_POST['ingredient_name']??''),'supplier_name'=>(string)($_POST['supplier_name']??''),'supplier_lot_code'=>(string)($_POST['supplier_lot_code']??'')]);
    $notice='Ingredient lot created.';
  }elseif($action==='hold'){
    $hold=!empty($_POST['hold']);$svc->setHold($id,$hold);
    $audit->record((int)$_SESSION['admin_id'],'ingredient_lot_hold_changed','ingredient_lot',$id,$hold?'Ingredient lot placed on hold.':'Ingredient lot released from hold.');
    $notice=$hold?'Ingredient lot placed on hold.':'Ingredient lot released from hold.';
  }elseif($action==='recall'){
    require_admin_roles(['super_admin','admin']);
    $affected=$svc->recall($id,(string)($_POST['reason']??''),(int)$_SESSION['admin_id']);
    $audit->record((int)$_SESSION['admin_id'],'ingredient_lot_recalled','ingredient_lot',$id,'Ingredient supplier lot recalled.',[],['affected_orders'=>count($affected)]);
    $notice='Ingredient lot recalled. '.count($affected).' affected order'.(count($affected)===1?'':'s').' identified.';
  }elseif($action==='notify_recall'){
    require_admin_roles(['super_admin','admin']);
    $lot=$svc->lot($id);if($lot['status']!=='recalled')throw new InvalidArgumentException('Recall notices can only be sent for recalled ingredient lots.');
    $n=new NotificationService($db);$queued=0;$failed=0;
    foreach($lot['orders'] as $o){
      $body="Important product notice for order {$o['order_number']}.\n\nAn ingredient supplier lot used in a production batch associated with this order has been recalled. Ingredient: {$lot['ingredient_name']}. Supplier: {$lot['supplier_name']}. Supplier lot: {$lot['supplier_lot_code']}. Reason: {$lot['recall_reason']}\n\nPlease do not consume the affected product and contact Fudge Donuts support for assistance.";
      $key='ingredient-recall:'.$id.':'.$o['id'];
      try{
        $n->queue((string)$o['email'],'Important notice about Fudge Donuts order '.$o['order_number'],$body,$key);
        $q=$db->prepare('SELECT id,status FROM notification_outbox WHERE idempotency_key=?');$q->execute([$key]);$msg=$q->fetch();
        if($msg && $msg['status']==='failed')$n->retry((int)$msg['id']);
        $queued++;
      }catch(Throwable){$failed++;}
    }
    $audit->record((int)$_SESSION['admin_id'],'ingredient_lot_recall_notices','ingredient_lot',$id,'Ingredient recall notices queued.',[],['affected_orders'=>count($lot['orders']),'queued'=>$queued,'failed'=>$failed]);
    $notice=$queued.' recall notice'.($queued===1?'':'s').' queued'.($failed?' · '.$failed.' failed to queue':'').'.';
  }elseif($action==='link_batch'){
    $code=strtoupper(trim((string)($_POST['batch_code']??'')));$q=$db->prepare('SELECT id FROM production_batches WHERE batch_code=?');$q->execute([$code]);$batchId=(int)$q->fetchColumn();
    if($batchId<1)throw new InvalidArgumentException('Production batch code was not found.');
    $qty=trim((string)($_POST['quantity_used']??''));$qty=$qty===''?null:(float)$qty;
    $svc->linkBatch($batchId,$id,$qty,(string)($_POST['quantity_unit']??''),(int)$_SESSION['admin_id']);
    $audit->record((int)$_SESSION['admin_id'],'ingredient_lot_batch_linked','ingredient_lot',$id,'Ingredient lot linked to production batch.',[],['batch_id'=>$batchId,'batch_code'=>$code,'quantity_used'=>$qty,'quantity_unit'=>(string)($_POST['quantity_unit']??'')]);
    $notice='Production batch linked to ingredient lot.';
  }elseif($action==='unlink_batch'){
    $batchId=(int)($_POST['batch_id']??0);$svc->unlinkBatch($batchId,$id);
    $audit->record((int)$_SESSION['admin_id'],'ingredient_lot_batch_unlinked','ingredient_lot',$id,'Ingredient lot removed from production batch.',[],['batch_id'=>$batchId]);
    $notice='Production batch link removed.';
  }else throw new InvalidArgumentException('Unsupported ingredient lot action.');
 }catch(Throwable $e){$error=$e->getMessage();}
}

$status=trim((string)($_GET['status']??''));
try{$lots=$svc->lots($status?:null);}catch(Throwable $e){$error=$e->getMessage();$status='';$lots=$svc->lots();}
$summary=$svc->summary();$lot=$id?$svc->lot($id):null;
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Ingredient Lots · Fudge Donuts Admin</title><link rel="stylesheet" href="<?=htmlspecialchars(asset_url('/assets/app.css'))?>"></head><body class="admin-body">
<a class="skip-link" href="#admin-main">Skip to admin content</a>
<header class="admin-topbar"><a class="admin-brand" href="/admin.php">Fudge Donuts <span>Admin</span></a><nav><a href="/admin.php">Dashboard</a><a href="/admin-inventory.php">Inventory</a><a href="/admin-ingredient-lots.php" class="active">Ingredients</a><a href="/admin-recipes.php">Recipes</a><a href="/admin-batches.php">Batches</a><a href="/admin-orders.php">Orders</a><a href="/admin-support.php">Support</a></nav></header>
<main id="admin-main" tabindex="-1" class="admin-shell">
<div class="admin-page-head"><div><p class="eyebrow">Supplier provenance</p><h1>Ingredient Lots</h1><p class="admin-welcome">Trace supplier lots into finished production batches and customer orders.</p></div></div>
<?php if($error):?><div class="notice error" role="alert"><?=htmlspecialchars($error)?></div><?php endif;?><?php if($notice):?><div class="notice" role="status"><?=htmlspecialchars($notice)?></div><?php endif;?>

<section class="dashboard-kpis"><article class="kpi-card"><span>Active</span><strong><?=$summary['active']?></strong></article><article class="kpi-card <?=$summary['hold']?'kpi-alert':''?>"><span>Hold</span><strong><?=$summary['hold']?></strong></article><article class="kpi-card <?=$summary['recalled']?'kpi-alert':''?>"><span>Recalled</span><strong><?=$summary['recalled']?></strong></article><article class="kpi-card <?=$summary['expired_active']||$summary['unlinked_batches']?'kpi-alert':''?>"><span>Expired / unlinked batches</span><strong><?=$summary['expired_active']?> / <?=$summary['unlinked_batches']?></strong></article></section>

<section class="dashboard-grid dashboard-secondary"><div class="dashboard-panel"><h2>Supplier lot ledger</h2><div class="order-filters"><a class="<?=!$status?'active':''?>" href="/admin-ingredient-lots.php">All</a><?php foreach(['active','hold','recalled'] as $s):?><a class="<?=$status===$s?'active':''?>" href="/admin-ingredient-lots.php?status=<?=$s?>"><?=htmlspecialchars(ucfirst($s))?></a><?php endforeach;?></div><div class="dashboard-table-wrap"><table class="dashboard-table"><thead><tr><th>Ingredient</th><th>Supplier lot</th><th>Received</th><th>Best by</th><th>Status</th><th>Batches</th></tr></thead><tbody><?php if(!$lots):?><tr><td colspan="6" class="empty-cell">No ingredient lots in this view.</td></tr><?php endif;?><?php foreach($lots as $row):?><tr><td><a href="/admin-ingredient-lots.php?id=<?=(int)$row['id']?>"><strong><?=htmlspecialchars($row['ingredient_name'])?></strong></a><small><?=htmlspecialchars($row['supplier_name'])?></small></td><td><?=htmlspecialchars($row['supplier_lot_code'])?></td><td><?=htmlspecialchars($row['received_at'])?></td><td><?=htmlspecialchars((string)$row['best_by_date'])?></td><td><?=htmlspecialchars(ucfirst($row['status']))?></td><td><?=(int)$row['batch_count']?></td></tr><?php endforeach;?></tbody></table></div></div>

<div class="dashboard-panel"><h2>Receive ingredient lot</h2><form method="post" class="admin-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="create">
<label>Ingredient name<input name="ingredient_name" required maxlength="190"></label><label>Supplier<input name="supplier_name" required maxlength="190"></label><label>Supplier lot code<input name="supplier_lot_code" required maxlength="120"></label><label>Received at<input type="datetime-local" name="received_at" required></label><label>Best-by date<input type="date" name="best_by_date"></label><div class="two"><label>Quantity received<input type="number" step="0.001" min="0.001" name="quantity_received"></label><label>Unit<input name="quantity_unit" maxlength="32" placeholder="lb, oz, kg"></label></div><label>Notes<textarea name="notes" maxlength="4000"></textarea></label><button class="button">Add ingredient lot</button></form></div></section>

<?php if($lot):?><section class="dashboard-panel <?=$lot['status']==='recalled'?'alert-panel':''?>"><div class="panel-head"><div><p class="eyebrow"><?=htmlspecialchars($lot['supplier_name'])?> · <?=htmlspecialchars($lot['supplier_lot_code'])?></p><h2><?=htmlspecialchars($lot['ingredient_name'])?></h2></div><a class="button secondary" href="/admin-ingredient-affected.csv.php?id=<?=$id?>">Export affected orders</a></div>
<p>Status: <strong><?=htmlspecialchars(ucfirst($lot['status']))?></strong> · Received <?=htmlspecialchars($lot['received_at'])?><?php if($lot['best_by_date']):?> · Best by <?=htmlspecialchars($lot['best_by_date'])?><?php endif;?></p><?php if($lot['notes']):?><p><?=nl2br(htmlspecialchars($lot['notes']))?></p><?php endif;?><?php if($lot['recall_reason']):?><div class="notice error"><strong>Recall reason:</strong> <?=htmlspecialchars($lot['recall_reason'])?></div><?php endif;?>

<h3>Production batches using this lot</h3><div class="dashboard-table-wrap"><table class="dashboard-table"><thead><tr><th>Batch</th><th>Flavor</th><th>Produced</th><th>Status</th><th>Quantity used</th><th></th></tr></thead><tbody><?php if(!$lot['batches']):?><tr><td colspan="6" class="empty-cell">No production batches are linked yet.</td></tr><?php endif;?><?php foreach($lot['batches'] as $b):?><tr><td><a href="/admin-batches.php?id=<?=(int)$b['id']?>"><?=htmlspecialchars($b['batch_code'])?></a></td><td><?=htmlspecialchars($b['flavor_name'])?></td><td><?=htmlspecialchars($b['produced_at'])?></td><td><?=htmlspecialchars($b['status'])?></td><td><?=htmlspecialchars((string)$b['quantity_used'].' '.(string)$b['quantity_unit'])?></td><td><form method="post"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="unlink_batch"><input type="hidden" name="lot_id" value="<?=$id?>"><input type="hidden" name="batch_id" value="<?=(int)$b['id']?>"><button class="link">Unlink</button></form></td></tr><?php endforeach;?></tbody></table></div>
<?php if($lot['status']==='active'):?><form method="post" class="inline-admin"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="link_batch"><input type="hidden" name="lot_id" value="<?=$id?>"><label>Production batch code<input name="batch_code" required placeholder="LOT-..."></label><label>Quantity used<input type="number" step="0.001" min="0.001" name="quantity_used"></label><label>Unit<input name="quantity_unit" maxlength="32"></label><button class="button secondary">Link batch</button></form><?php endif;?>

<h3>Affected customer orders</h3><div class="dashboard-table-wrap"><table class="dashboard-table"><thead><tr><th>Order</th><th>Customer</th><th>Status</th><th>Created</th></tr></thead><tbody><?php if(!$lot['orders']):?><tr><td colspan="4" class="empty-cell">No customer orders are currently downstream of this ingredient lot.</td></tr><?php endif;?><?php foreach($lot['orders'] as $o):?><tr><td><a href="/admin-order.php?id=<?=(int)$o['id']?>"><?=htmlspecialchars($o['order_number'])?></a></td><td><?=htmlspecialchars($o['first_name'].' '.$o['last_name'])?><small><?=htmlspecialchars($o['email'])?></small></td><td><?=htmlspecialchars($o['status'])?></td><td><?=htmlspecialchars($o['created_at'])?></td></tr><?php endforeach;?></tbody></table></div>

<?php if(in_array($lot['status'],['active','hold'],true)):?><form method="post" class="actions"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="hold"><input type="hidden" name="lot_id" value="<?=$id?>"><input type="hidden" name="hold" value="<?=$lot['status']==='hold'?'0':'1'?>"><button class="button secondary"><?=$lot['status']==='hold'?'Release hold':'Place on hold'?></button></form><?php endif;?>
<?php if($lot['status']!=='recalled' && admin_has_role(['super_admin','admin'])):?><form method="post" class="admin-form recall-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="recall"><input type="hidden" name="lot_id" value="<?=$id?>"><label>Recall reason<textarea name="reason" required maxlength="4000"></textarea></label><button class="button">Recall ingredient lot</button></form><?php endif;?>
<?php if($lot['status']==='recalled' && admin_has_role(['super_admin','admin'])):?><form method="post" class="actions"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="notify_recall"><input type="hidden" name="lot_id" value="<?=$id?>"><button class="button">Queue / retry failed recall notices</button></form><?php endif;?>
</section><?php endif;?>
</main></body></html>