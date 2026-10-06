<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{AdminAuditService,AdminService,Database,FulfillmentOperationsService,NotificationService};
require_admin_roles(['super_admin','admin','fulfillment']);

$db=Database::connection();$admin=new AdminService($db);$ops=new FulfillmentOperationsService($db);$audit=new AdminAuditService($db);$error='';$notice=(string)($_SESSION['admin_orders_flash']??'');unset($_SESSION['admin_orders_flash']);
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf($_POST['_csrf']??null);
 try{
   $action=(string)($_POST['action']??'');
   if($action==='batch'){
     $ids=array_map('intval',(array)($_POST['order_ids']??[]));$to=(string)($_POST['status']??'');
     $count=$ops->batchTransition($ids,$to);
     $notifications=new NotificationService($db);$notificationFailures=0;
     foreach($ids as $id){
       $order=$admin->order($id);
       if(!$order)continue;
       try{$notifications->queueStatusUpdate($order,$to);}catch(Throwable){$notificationFailures++;}
     }
     $audit->record((int)$_SESSION['admin_id'],'order_batch_status_changed','order_batch',implode(',',$ids),"{$count} orders moved to {$to}.",[],['order_ids'=>$ids,'status'=>$to,'notification_failures'=>$notificationFailures]);
     $_SESSION['admin_orders_flash']=$count.' order'.($count===1?'':'s').' moved to '.str_replace('_',' ',$to).'.'.($notificationFailures?' '.$notificationFailures.' status notification'.($notificationFailures===1?'':'s').' could not be queued.':'');
     $redirect='/admin-orders.php';$current=trim((string)($_GET['status']??''));
     if($current!=='')$redirect.='?status='.rawurlencode($current);
     header('Location: '.$redirect,true,303);exit;
   }
 }catch(Throwable $e){$error=$e->getMessage();}
}
$statusFilter=trim((string)($_GET['status']??''));
try{$orders=$admin->orders(200,$statusFilter?:null);}catch(Throwable $e){$error=$e->getMessage();$statusFilter='';$orders=$admin->orders();}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="<?=htmlspecialchars(asset_url('/assets/app.css'))?>"><title>Orders · Admin</title></head><body class="admin-body">
<header class="admin-topbar"><a class="admin-brand" href="/admin.php">Fudge Donuts <span>Admin</span></a><nav><a href="/admin.php">Dashboard</a><a href="/admin-flavors.php">Flavors</a><a href="/admin-packs.php">Packs</a><a class="active" href="/admin-orders.php">Orders</a><a href="/admin-customers.php">Customers</a><a href="/admin-support.php">Support</a><a href="/admin-inventory.php">Inventory</a><a href="/admin-shipping.php">Shipping</a><a href="/admin-tax.php">Tax</a><a href="/admin-reports.php">Reports</a><a href="/admin-operations.php">Operations</a></nav></header>
<main class="admin-shell">
<div class="admin-page-head"><div><p class="eyebrow">Operations</p><h1>Orders</h1></div><div class="admin-quick-actions"><a class="button secondary" href="/admin-fulfillment.csv.php?type=shipping&status=ready">Export ready shipping CSV</a><a class="button secondary" href="/admin-pickup-sheet.php?status=ready" target="_blank">Print pickup sheet</a></div></div>
<div class="order-filters"><a class="<?=!$statusFilter?'active':''?>" href="/admin-orders.php">All</a><?php foreach(['paid'=>'New','preparing'=>'Preparing','ready'=>'Ready','shipped'=>'Shipped'] as $s=>$label):?><a class="<?=$statusFilter===$s?'active':''?>" href="/admin-orders.php?status=<?=urlencode($s)?>"><?=htmlspecialchars($label)?></a><?php endforeach;?></div>
<?php if($error):?><div class="notice error"><?=htmlspecialchars($error)?></div><?php endif;?><?php if($notice):?><div class="notice"><?=htmlspecialchars($notice)?></div><?php endif;?>
<form method="post" class="batch-orders-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="action" value="batch">
<div class="batch-toolbar"><strong>Batch fulfillment</strong><select name="status" required><option value="">Choose action…</option><option value="preparing">Move paid orders → Preparing</option><option value="ready">Move preparing orders → Ready</option></select><button class="button secondary">Apply to selected</button><small>Shipping/tracking changes remain individual.</small></div>
<div class="admin-table-wrap"><table><thead><tr><th><span class="sr-only">Select</span></th><th>Order</th><th>Customer</th><th>Fulfillment</th><th>Total</th><th>Status</th><th>Documents</th></tr></thead><tbody>
<?php if(!$orders):?><tr><td colspan="7" class="empty-cell">No orders in this view.</td></tr><?php endif;?>
<?php foreach($orders as $o):?><tr><td><input type="checkbox" name="order_ids[]" value="<?=(int)$o['id']?>" aria-label="Select <?=htmlspecialchars($o['order_number'])?>"></td><td><a href="/admin-order.php?id=<?=(int)$o['id']?>"><strong><?=htmlspecialchars($o['order_number'])?></strong></a><br><small><?=htmlspecialchars($o['created_at'])?></small></td><td><?=htmlspecialchars($o['first_name'].' '.$o['last_name'])?><br><small><?=htmlspecialchars($o['email'])?></small></td><td><?=htmlspecialchars($o['fulfillment_name'])?><br><small><?=htmlspecialchars(ucfirst($o['fulfillment_type']))?></small></td><td><?=money((int)$o['total_cents'])?></td><td><span class="status status-<?=htmlspecialchars($o['status'])?>"><?=htmlspecialchars(str_replace('_',' ',$o['status']))?></span></td><td><a href="/admin-packing-slip.php?id=<?=(int)$o['id']?>" target="_blank">Packing slip</a></td></tr><?php endforeach;?>
</tbody></table></div></form>
</main></body></html>