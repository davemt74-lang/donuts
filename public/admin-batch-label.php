<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{BatchLabelService,Database};
require_admin_roles(['super_admin','admin','fulfillment']);

$id=(int)($_GET['id']??0);
try{$label=(new BatchLabelService(Database::connection()))->forBatch($id);}
catch(Throwable $e){http_response_code(404);exit('Batch label unavailable.');}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Batch Label <?=htmlspecialchars($label['batch_code'])?></title><link rel="stylesheet" href="/assets/app.css"></head><body class="batch-label-body">
<main class="batch-label">
<header><p class="eyebrow">Fudge Donuts</p><h1><?=htmlspecialchars($label['flavor_name'])?></h1><strong class="batch-label-code"><?=htmlspecialchars($label['batch_code'])?></strong></header>
<section class="batch-label-dates"><div><span>Produced</span><strong><?=htmlspecialchars($label['produced_date'])?></strong></div><div><span>Best by</span><strong><?=htmlspecialchars($label['best_by_date'])?></strong></div><div><span>Label version</span><strong><?=htmlspecialchars($label['label_version'])?></strong></div></section>
<section><h2>Ingredients</h2><p><?=htmlspecialchars($label['ingredient_statement'])?></p></section>
<section class="batch-label-allergen"><h2>Allergens</h2><p><strong><?=htmlspecialchars($label['allergen_statement'])?></strong></p><?php if($label['shared_kitchen_notice']!==''):?><p><?=htmlspecialchars($label['shared_kitchen_notice'])?></p><?php endif;?></section>
<section><h2>Storage</h2><p><?=htmlspecialchars($label['storage_instructions'])?></p></section>
<footer><span>Net wt. <?=htmlspecialchars(rtrim(rtrim(number_format((float)$label['net_weight_oz'],2,'.',''),'0'),'.'))?> oz each</span><span>Shelf life <?=(int)$label['shelf_life_days']?> days</span><span>Batch qty <?=(int)$label['quantity_produced']?></span></footer>
</main>
<script>window.addEventListener('load',()=>{if(new URLSearchParams(location.search).get('print')==='1')window.print();});</script>
</body></html>