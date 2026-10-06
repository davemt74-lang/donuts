<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{Database,FulfillmentOperationsService};
require_admin_roles(['super_admin','admin','fulfillment']);

$id=(int)($_GET['id']??0);
try{$order=(new FulfillmentOperationsService(Database::connection()))->packingSlip($id);}
catch(Throwable $e){http_response_code(404);exit('Packing slip unavailable.');}
$hidePrice=!empty($order['gift_options']['hide_price']);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Packing Slip <?=htmlspecialchars($order['order_number'])?></title><link rel="stylesheet" href="<?=htmlspecialchars(asset_url('/assets/app.css'))?>"></head><body class="packing-slip-body">
<main class="packing-slip">
<header class="packing-slip-head"><div><p class="eyebrow">Fudge Donuts</p><h1>Packing Slip</h1></div><div><strong><?=htmlspecialchars($order['order_number'])?></strong><small><?=htmlspecialchars($order['created_at'])?></small></div></header>
<section class="packing-slip-grid">
<div><h2>Customer</h2><p><strong><?=htmlspecialchars($order['first_name'].' '.$order['last_name'])?></strong><br><?=htmlspecialchars($order['email'])?><?php if($order['phone']):?><br><?=htmlspecialchars($order['phone'])?><?php endif;?></p></div>
<div><h2><?=htmlspecialchars($order['fulfillment_type']==='pickup'?'Pickup':'Ship to')?></h2><p><?=htmlspecialchars($order['line1'])?><?php if($order['line2']):?><br><?=htmlspecialchars($order['line2'])?><?php endif;?><br><?=htmlspecialchars($order['city'].', '.$order['region'].' '.$order['postal_code'])?><br><?=htmlspecialchars($order['country'])?></p></div>
<div><h2>Fulfillment</h2><p><strong><?=htmlspecialchars($order['fulfillment_name'])?></strong><br><?=htmlspecialchars(ucwords(str_replace('_',' ',$order['status'])))?></p></div>
</section>
<?php if($order['is_gift']):?><section class="packing-gift"><h2>Gift order</h2><p><?=nl2br(htmlspecialchars($order['gift_message']?:'No gift message.'))?></p><?php if($order['gift_options']):?><small>Packaging: <?=htmlspecialchars(ucwords(str_replace('-',' ',$order['gift_options']['packaging'])))?><?php if($order['gift_options']['requested_delivery_date']):?> · Requested <?=htmlspecialchars($order['gift_options']['requested_delivery_date'])?><?php endif;?></small><?php endif;?></section><?php endif;?>
<section><h2>Items</h2><table class="packing-table"><thead><tr><th>Box</th><th>Qty</th><?php if(!$hidePrice):?><th>Line total</th><?php endif;?></tr></thead><tbody>
<?php foreach($order['items'] as $item):$cfg=$item['configuration'];?><tr><td><strong><?=htmlspecialchars($cfg['name']??(($item['pack_size']??'').' Pack'))?></strong><div class="packing-flavors"><?php foreach($cfg['items']??[] as $f):?><span><?=htmlspecialchars($f['name']??'Flavor')?> × <?=(int)($f['quantity']??0)?></span><?php endforeach;?></div></td><td><?=(int)$item['quantity']?></td><?php if(!$hidePrice):?><td><?=money((int)$item['line_total_cents'])?></td><?php endif;?></tr><?php endforeach;?>
</tbody></table></section>
<?php if(!$hidePrice):?><section class="packing-totals"><div><span>Subtotal</span><strong><?=money((int)$order['subtotal_cents'])?></strong></div><div><span>Discount</span><strong>−<?=money((int)$order['discount_cents'])?></strong></div><div><span>Shipping</span><strong><?=money((int)$order['shipping_cents'])?></strong></div><div><span>Tax</span><strong><?=money((int)$order['tax_cents'])?></strong></div><div class="grand"><span>Total</span><strong><?=money((int)$order['total_cents'])?></strong></div></section><?php endif;?>
<footer class="packing-footer"><span>Checked by: ____________________</span><span>Packed at: ____________________</span></footer>
</main>
<script>window.addEventListener('load',()=>{if(new URLSearchParams(location.search).get('print')==='1')window.print();});</script>
</body></html>