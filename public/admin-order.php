<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{AdminAuditService,AdminService,Database,FulfillmentService,NotificationService,RefundService,StripeService,TaxService};
require_admin_roles(['super_admin','admin','fulfillment']);
$db=Database::connection();$admin=new AdminService($db);$audit=new AdminAuditService($db);$refunds=new RefundService($db);$fulfillment=new FulfillmentService($db);$id=(int)($_GET['id']??$_POST['id']??0);$order=$admin->order($id);
if(!$order){
    \FudgeDonuts\HttpResponseService::send(
        404,
        'Order not found.',
        'The order may have been removed from this view or the link may be outdated.',
        [['label'=>'Back to orders','href'=>'/admin-orders.php']],
        null,
        true
    );
}
$error='';$notice='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf($_POST['_csrf']??null);
 try{
   $action=(string)($_POST['action']??'');
   if($action==='status'){
     $newStatus=(string)$_POST['status'];$beforeStatus=(string)$order['status'];
     $admin->transitionOrder($id,$newStatus,(string)($_POST['note']??''));
     $audit->record((int)$_SESSION['admin_id'],'order_status_changed','order',$id,"Order {$order['order_number']} moved {$beforeStatus} → {$newStatus}.",['status'=>$beforeStatus],['status'=>$newStatus]);
     if($newStatus==='shipped')$fulfillment->markShipped($id);
     if($newStatus==='delivered')$fulfillment->markDelivered($id);
     $order=$admin->order($id);(new NotificationService($db))->queueFulfillmentUpdate($order,$fulfillment->details($id));
     $notice='Order status updated.';
   }elseif($action==='fulfillment'){
     $beforeFulfillment=$fulfillment->details($id);$afterFulfillment=$fulfillment->save($id,$_POST);$audit->record((int)$_SESSION['admin_id'],'fulfillment_updated','order',$id,'Order fulfillment details updated.',$beforeFulfillment,$afterFulfillment);$order=$admin->order($id);(new NotificationService($db))->queueFulfillmentUpdate($order,$fulfillment->details($id));$notice='Fulfillment details saved.';
   }elseif($action==='refund'){
     require_admin_roles(['super_admin','admin']);
     $amount=(int)$_POST['amount_cents'];$rid=$refunds->create($id,$amount,(string)($_POST['reason']??''),(int)$_SESSION['admin_id']);
     try{
       if(empty($order['stripe_payment_intent_id'])) throw new RuntimeException('Stripe payment intent is not available for this order.');
       $result=(new StripeService((string)env('STRIPE_SECRET_KEY',''),(string)env('STRIPE_WEBHOOK_SECRET','')))->createRefund([
         'payment_intent'=>(string)$order['stripe_payment_intent_id'],
         'amount'=>(string)$amount,
         'metadata[order_id]'=>(string)$id,
         'metadata[refund_id]'=>(string)$rid,
       ],'refund_'.$rid);
       $refunds->markSucceeded($rid,(string)$result['id']);$audit->record((int)$_SESSION['admin_id'],'refund_issued','order',$id,'Stripe refund issued.',['status'=>$order['status']],['refund_id'=>$rid,'amount_cents'=>$amount,'stripe_refund_id'=>(string)$result['id']]);$notice='Refund completed.';
     }catch(Throwable $e){$refunds->markFailed($rid,$e->getMessage());throw $e;}
   }elseif($action==='cancel_resolution'){
     $requestId=(int)$_POST['request_id'];$resolution=(string)$_POST['resolution'];$refunds->resolveCancellation($requestId,$resolution,(int)$_SESSION['admin_id']);
     if($resolution==='approved' && in_array($order['status'],['pending_payment','paid','preparing'],true)){$admin->transitionOrder($id,'cancelled','Customer cancellation request approved.');}
     $audit->record((int)$_SESSION['admin_id'],'cancellation_resolved','order',$id,'Customer cancellation request '.$resolution.'.',[],['resolution'=>$resolution,'request_id'=>$requestId]);
     $notice='Cancellation request updated.';
   }
   $order=$admin->order($id);
 }catch(Throwable $e){$error=$e->getMessage();}
}
$refundRows=$refunds->forOrder($id);$cancel=$refunds->pendingCancellation($id);$refundable=$refunds->refundableCents($id);$fulfillmentDetails=$fulfillment->details($id);$taxDetail=(new TaxService($db))->orderDetail($id);
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="<?=htmlspecialchars(asset_url('/assets/app.css'))?>"><title><?=htmlspecialchars($order['order_number'])?> · Admin</title></head><body class="admin-body">
<header class="admin-topbar"><a class="admin-brand" href="/admin.php">Fudge Donuts <span>Admin</span></a><nav><a href="/admin.php">Dashboard</a><a class="active" href="/admin-orders.php">Orders</a><a href="/admin-inventory.php">Inventory</a><a href="/admin-shipping.php">Shipping</a><a href="/admin-reports.php">Reports</a><a href="/admin-notifications.php">Email</a><a href="/admin-marketing.php">Marketing</a><a href="/admin-audit.php">Audit</a><a href="/admin-operations.php">Operations</a></nav></header>
<main class="admin-shell"><div class="admin-page-head"><div><p class="eyebrow">Order detail</p><h1><?=htmlspecialchars($order['order_number'])?></h1></div><a class="button secondary" href="/admin-orders.php">Back to orders</a></div>
<?php if($error):?><div class="notice error"><?=htmlspecialchars($error)?></div><?php endif;?><?php if($notice):?><div class="notice"><?=htmlspecialchars($notice)?></div><?php endif;?>
<div class="dashboard-grid dashboard-main"><section class="dashboard-panel"><h2>Customer & fulfillment</h2><p><strong><?=htmlspecialchars($order['first_name'].' '.$order['last_name'])?></strong><br><?=htmlspecialchars($order['email'])?><br><?=htmlspecialchars($order['line1'])?><br><?=htmlspecialchars($order['city'].', '.$order['region'].' '.$order['postal_code'])?></p><p><?=htmlspecialchars($order['fulfillment_name'])?> · <?=htmlspecialchars(ucwords(str_replace('_',' ',$order['status'])))?></p></section><section class="dashboard-panel"><h2>Totals</h2><div class="review-total"><span>Subtotal</span><strong><?=money((int)$order['subtotal_cents'])?></strong></div><div class="review-total"><span>Discounts</span><strong>−<?=money((int)$order['discount_cents'])?></strong></div><div class="review-total"><span>Shipping</span><strong><?=money((int)$order['shipping_cents'])?></strong></div><div class="review-total"><span>Tax</span><strong><?=money((int)$order['tax_cents'])?></strong></div><div class="review-total grand"><span>Total</span><strong><?=money((int)$order['total_cents'])?></strong></div></section></div>
<?php if($taxDetail):?><section class="dashboard-panel"><h2>Tax configuration snapshot</h2><div class="review-total"><span>Automatic Tax</span><strong><?=(int)$taxDetail['automatic_tax_enabled']?'Enabled':'Disabled'?></strong></div><div class="review-total"><span>Stripe tax code</span><strong><?=htmlspecialchars($taxDetail['product_tax_code']?:'Stripe default')?></strong></div><div class="review-total"><span>Behavior</span><strong><?=htmlspecialchars(ucfirst($taxDetail['tax_behavior']))?></strong></div><div class="review-total"><span>Collected</span><strong><?=money((int)$taxDetail['stripe_tax_cents'])?></strong></div></section><?php endif;?>
<?php $rec=$order['payment_reconciliation']??null;if($rec):?><section class="dashboard-panel <?=($rec['status']==='mismatch'?'alert-panel':'')?>"><h2>Payment reconciliation</h2><div class="review-total"><span>Status</span><strong><?=htmlspecialchars(ucwords((string)$rec['status']))?></strong></div><div class="review-total"><span>Expected before tax</span><strong><?=money((int)$rec['expected_pre_tax_cents'])?></strong></div><div class="review-total"><span>Stripe subtotal</span><strong><?=money((int)$rec['stripe_subtotal_cents'])?></strong></div><div class="review-total"><span>Stripe tax</span><strong><?=money((int)$rec['stripe_tax_cents'])?></strong></div><div class="review-total grand"><span>Stripe total</span><strong><?=money((int)$rec['stripe_total_cents'])?></strong></div><p><small>Currency: <?=htmlspecialchars(strtoupper((string)$rec['currency']))?> · Reconciled <?=htmlspecialchars((string)$rec['reconciled_at'])?></small></p><?php if($rec['status']==='mismatch'):?><div class="notice error">Stripe reported a payment total or currency that does not match the server-calculated order. Do not fulfill this order until the payment discrepancy is resolved.</div><?php endif;?></section><?php endif;?>
<section class="dashboard-panel"><h2>Items</h2><?php foreach($order['items'] as $item):$cfg=json_decode((string)$item['configuration_json'],true);?><div class="account-order-item"><div><strong><?=htmlspecialchars(($cfg['name']??'Custom '.$item['pack_size'].' Pack'))?> × <?=(int)$item['quantity']?></strong><p><?php foreach($cfg['items']??[] as $f):?><?=htmlspecialchars($f['name'])?> × <?=(int)$f['quantity']?> · <?php endforeach;?></p></div><strong><?=money((int)$item['line_total_cents'])?></strong></div><?php endforeach;?></section>
<?php if($cancel):?><section class="dashboard-panel alert-panel"><h2>Cancellation request</h2><p><?=htmlspecialchars($cancel['reason']?:'No reason provided.')?></p><form method="post" class="actions"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="cancel_resolution"><input type="hidden" name="request_id" value="<?=(int)$cancel['id']?>"><button class="button secondary" name="resolution" value="declined">Decline</button><button class="button" name="resolution" value="approved">Approve cancellation</button></form></section><?php endif;?>
<section class="dashboard-panel"><h2>Fulfillment details</h2><form method="post" class="admin-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="fulfillment"><?php if($order['fulfillment_type']==='shipping'):?><input name="carrier" placeholder="Carrier" value="<?=htmlspecialchars((string)$fulfillmentDetails['carrier'])?>"><input name="tracking_number" placeholder="Tracking number" value="<?=htmlspecialchars((string)$fulfillmentDetails['tracking_number'])?>"><input name="tracking_url" placeholder="https://…" value="<?=htmlspecialchars((string)$fulfillmentDetails['tracking_url'])?>"><?php else:?><textarea name="pickup_instructions" placeholder="Pickup instructions"><?=htmlspecialchars((string)$fulfillmentDetails['pickup_instructions'])?></textarea><label>Pickup ready at<input type="datetime-local" name="pickup_ready_at" value="<?=htmlspecialchars((string)$fulfillmentDetails['pickup_ready_at'])?>"></label><?php endif;?><button class="button secondary">Save fulfillment details</button></form></section>
<section class="dashboard-grid dashboard-secondary"><div class="dashboard-panel"><h2>Update status</h2><form method="post" class="admin-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="status"><select name="status"><option>preparing</option><option>ready</option><option>shipped</option><option>delivered</option><option>completed</option><option>cancelled</option></select><textarea name="note" placeholder="Internal note"></textarea><button class="button secondary">Update status</button></form></div>
<div class="dashboard-panel"><h2>Refund</h2><p>Refundable balance: <strong><?=money($refundable)?></strong></p><?php if($refundable>0 && admin_has_role(['super_admin','admin'])):?><form method="post" class="admin-form"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(csrf_token())?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="refund"><input type="number" min="1" max="<?=$refundable?>" name="amount_cents" value="<?=$refundable?>" required><input name="reason" placeholder="Refund reason"><button class="button">Issue Stripe refund</button></form><?php endif;?></div></section>
<?php if($refundRows):?><section class="dashboard-panel"><h2>Refund history</h2><table><thead><tr><th>Amount</th><th>Status</th><th>Reason</th><th>Date</th></tr></thead><tbody><?php foreach($refundRows as $r):?><tr><td><?=money((int)$r['amount_cents'])?></td><td><?=htmlspecialchars($r['status'])?></td><td><?=htmlspecialchars($r['reason'])?></td><td><?=htmlspecialchars($r['created_at'])?></td></tr><?php endforeach;?></tbody></table></section><?php endif;?>
</main></body></html>
