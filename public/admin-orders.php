<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{AdminService,Database,NotificationService};
if(empty($_SESSION['admin'])){header('Location: /admin.php');exit;}
$admin=new AdminService(Database::connection());$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf($_POST['_csrf']??null);
 try{$id=(int)($_POST['order_id']??0);$status=(string)($_POST['status']??'');$admin->transitionOrder($id,$status,(string)($_POST['note']??''));$order=$admin->order($id);if($order)(new NotificationService(Database::connection()))->queueStatusUpdate($order,$status);header('Location: /admin-orders.php');exit;}catch(Throwable $e){$error=$e->getMessage();}
}
$orders=$admin->orders();
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/app.css"><title>Orders · Admin</title></head><body>
<header class="nav"><a class="brand" href="/admin.php">Fudge Donuts Admin</a><nav><a href="/admin.php">Catalog</a><a href="/admin-orders.php">Orders</a><a href="/admin-inventory.php">Inventory</a><a href="/admin-settings.php">Store Settings</a></nav></header>
<main class="section"><p class="eyebrow">Operations</p><h1>Orders</h1><?php if($error):?><div class="notice error"><?=htmlspecialchars($error)?></div><?php endif;?>
<div class="admin-table-wrap"><table><thead><tr><th>Order</th><th>Customer</th><th>Fulfillment</th><th>Total</th><th>Status</th><th>Update</th></tr></thead><tbody>
<?php foreach($orders as $o):?><tr><td><strong><?=htmlspecialchars($o['order_number'])?></strong><br><small><?=htmlspecialchars($o['created_at'])?></small></td><td><?=htmlspecialchars($o['first_name'].' '.$o['last_name'])?><br><small><?=htmlspecialchars($o['email'])?></small></td><td><?=htmlspecialchars($o['fulfillment_name'])?></td><td><?=money((int)$o['total_cents'])?></td><td><span class="status status-<?=htmlspecialchars($o['status'])?>"><?=htmlspecialchars(str_replace('_',' ',$o['status']))?></span></td><td><form method="post" class="inline-admin"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="order_id" value="<?=(int)$o['id']?>"><select name="status"><option value="">Choose…</option><option>preparing</option><option>ready</option><option>shipped</option><option>delivered</option><option>completed</option><option>cancelled</option><option>refunded</option></select><input name="note" placeholder="Optional note"><button class="button secondary">Update</button></form></td></tr><?php endforeach;?>
</tbody></table></div></main></body></html>
