<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{Database,FulfillmentOperationsService};
require_admin_roles(['super_admin','admin','fulfillment']);

$status=trim((string)($_GET['status']??'ready'));
try{$rows=(new FulfillmentOperationsService(Database::connection()))->pickupRows($status?:null);}
catch(Throwable $e){http_response_code(400);exit('Invalid pickup sheet filter.');}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Pickup Sheet · Fudge Donuts</title><link rel="stylesheet" href="/assets/app.css"></head><body class="packing-slip-body">
<main class="packing-slip pickup-sheet"><header class="packing-slip-head"><div><p class="eyebrow">Fudge Donuts</p><h1>Pickup Sheet</h1></div><div><strong><?=htmlspecialchars(ucwords(str_replace('_',' ',$status?:'all')))?></strong><small><?=htmlspecialchars(gmdate('Y-m-d H:i'))?> UTC</small></div></header>
<table class="packing-table"><thead><tr><th>Ready</th><th>Order</th><th>Customer</th><th>Contact</th><th>Status</th></tr></thead><tbody>
<?php if(!$rows):?><tr><td colspan="5">No pickup orders in this view.</td></tr><?php endif;?>
<?php foreach($rows as $row):?><tr><td class="pickup-check">☐</td><td><strong><?=htmlspecialchars($row['order_number'])?></strong><br><small><?=htmlspecialchars($row['created_at'])?></small></td><td><?=htmlspecialchars($row['first_name'].' '.$row['last_name'])?></td><td><?=htmlspecialchars($row['email'])?><?php if($row['phone']):?><br><?=htmlspecialchars($row['phone'])?><?php endif;?></td><td><?=htmlspecialchars(ucwords(str_replace('_',' ',$row['status'])))?></td></tr><?php endforeach;?>
</tbody></table><footer class="packing-footer"><span>Prepared by: ____________________</span><span>Date: ____________________</span></footer></main>
<script>window.addEventListener('load',()=>window.print());</script></body></html>