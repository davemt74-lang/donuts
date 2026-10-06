<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{AdminAuditService,BatchTraceabilityService,CatalogRepository,Database,FinishedGoodsAllocationService};
require_admin_roles(['super_admin','admin','fulfillment']);
$db=Database::connection();$svc=new BatchTraceabilityService($db);$auto=new FinishedGoodsAllocationService($db);$audit=new AdminAuditService($db);$catalog=new CatalogRepository($db);
$orderId=(int)($_GET['id']??$_POST['order_id']??0);$error='';$notice='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf($_POST['_csrf']??null);
 try{
   $action=(string)($_POST['action']??'assign');
   if($action==='assign'){
     $svc->assignOrder($orderId,(array)($_POST['batch']??[]),(int)$_SESSION['admin_id']);
     $audit->record((int)$_SESSION['admin_id'],'order_batches_assigned','order',$orderId,'Production batches assigned to order.');
     $notice='Production batches assigned.';
   }elseif($action==='auto'){
     $plan=$auto->assign($orderId,(int)$_SESSION['admin_id']);
     $audit->record((int)$_SESSION['admin_id'],'order_batches_fefo_assigned','order',$orderId,'Production batches assigned automatically by FEFO.',[],['assignments'=>$plan['assignments']]);
     $notice=$plan['already_assigned']?'Order was already assigned.':'Safe FEFO batches assigned.';
   }elseif($action==='clear'){
     $before=$svc->assignmentsForOrder($orderId);$svc->unassignOrder($orderId);
     $audit->record((int)$_SESSION['admin_id'],'order_batches_cleared','order',$orderId,'Production batch assignments cleared for correction.',$before,[]);
     $notice='Batch assignments cleared. Inventory was restored.';
   }else throw new InvalidArgumentException('Unsupported batch assignment action.');
 }catch(Throwable $e){$error=$e->getMessage();}
}
$needed=$svc->requiredFlavorQuantities($orderId);$assigned=$svc->assignmentsForOrder($orderId);$active=$svc->batches('active');
$flavors=[];foreach($catalog->flavors(false) as $f)$flavors[(int)$f['id']]=$f;
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Order Batch Assignment · Fudge Donuts Admin</title><link rel="stylesheet" href="<?=htmlspecialchars(asset_url('/assets/app.css'))?>"></head><body class="admin-body">
<header class="admin-topbar"><a class="admin-brand" href="/admin.php">Fudge Donuts <span>Admin</span></a><nav><a href="/admin-orders.php">Orders</a><a href="/admin-batches.php">Batches</a><a href="/admin-batch-allocation.php">Allocation</a></nav></header>
<main class="admin-shell"><div class="admin-page-head"><div><p class="eyebrow">Traceability</p><h1>Assign Production Batches</h1></div><a class="button secondary" href="/admin-order.php?id=<?=$orderId?>">Back to order</a></div>
<?php if($error):?><div class="notice error" role="alert"><?=htmlspecialchars($error)?></div><?php endif;?><?php if($notice):?><div class="notice" role="status"><?=htmlspecialchars($notice)?></div><?php endif;?>
<?php if($assigned):?><section class="dashboard-panel"><div class="panel-head"><h2>Assigned batches</h2><form method="post"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="order_id" value="<?=$orderId?>"><input type="hidden" name="action" value="clear"><button class="button secondary">Clear & reassign</button></form></div><table class="dashboard-table"><thead><tr><th>Flavor</th><th>Batch</th><th>Qty</th><th>Status</th></tr></thead><tbody><?php foreach($assigned as $a):?><tr><td><?=htmlspecialchars($a['flavor_name'])?></td><td><a href="/admin-batches.php?id=<?=(int)$a['batch_id']?>"><?=htmlspecialchars($a['batch_code'])?></a></td><td><?=(int)$a['quantity']?></td><td><?=htmlspecialchars($a['status'])?></td></tr><?php endforeach;?></tbody></table><p class="muted">Assignments can be corrected only while the order is Preparing or Ready. Shipped traceability is immutable.</p></section>
<?php else:?><section class="dashboard-panel"><div class="panel-head"><h2>Order requirements</h2><form method="post"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="order_id" value="<?=$orderId?>"><input type="hidden" name="action" value="auto"><button class="button secondary">Auto-allocate FEFO</button></form></div><form method="post" class="admin-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="order_id" value="<?=$orderId?>"><input type="hidden" name="action" value="assign">
<?php foreach($needed as $fid=>$qty):?><fieldset class="batch-assignment-fieldset"><legend><?=htmlspecialchars($flavors[$fid]['name']??('Flavor '.$fid))?> · <?=$qty?> required</legend><?php $options=array_values(array_filter($active,fn($b)=>(int)$b['flavor_id']===(int)$fid));if(!$options):?><div class="notice error">No active batch is available for this flavor.</div><?php endif;?><?php foreach($options as $b):?><label><?=htmlspecialchars($b['batch_code'])?> · <?=(int)$b['quantity_remaining']?> available · best by <?=htmlspecialchars((string)$b['best_by_date'])?><input type="number" min="0" max="<?=(int)$b['quantity_remaining']?>" name="batch[<?=(int)$b['id']?>]" value="0"></label><?php endforeach;?></fieldset><?php endforeach;?>
<button class="button" <?=$needed?'':'disabled'?>>Assign exact batch quantities</button></form></section><?php endif;?>
</main></body></html>