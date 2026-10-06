<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{Database,ReportingService};
if(empty($_SESSION['admin'])){header('Location: /admin.php');exit;}
$r=new ReportingService(Database::connection());
$overview=$r->overview($_GET['start']??null,$_GET['end']??null);$statuses=$r->byStatus();$packs=$r->packPerformance();$flavors=$r->flavorPerformance();$daily=$r->dailySales(30);
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/app.css"><title>Reports · Admin</title></head><body>
<header class="nav"><a class="brand" href="/admin.php">Fudge Donuts Admin</a><nav><a href="/admin.php">Catalog</a><a href="/admin-orders.php">Orders</a><a href="/admin-inventory.php">Inventory</a><a href="/admin-promotions.php">Promotions</a><a href="/admin-content.php">Content</a><a href="/admin-reports.php">Reports</a><a href="/admin-settings.php">Settings</a></nav></header>
<main class="section"><p class="eyebrow">Performance</p><h1>Store reports</h1>
<form method="get" class="inline-admin"><input type="date" name="start" value="<?=htmlspecialchars((string)($_GET['start']??''))?>"><input type="date" name="end" value="<?=htmlspecialchars((string)($_GET['end']??''))?>"><button class="button secondary">Filter</button><a class="button" href="/admin-report.csv.php">Export CSV</a></form>
<div class="metric-grid"><article><span>Orders</span><strong><?=(int)$overview['orders']?></strong></article><article><span>Revenue</span><strong><?=money((int)$overview['revenue_cents'])?></strong></article><article><span>Average order</span><strong><?=money((int)$overview['aov_cents'])?></strong></article><article><span>Discounts</span><strong><?=money((int)$overview['discount_cents'])?></strong></article></div>
<div class="report-grid"><section><h2>Pack performance</h2><table><thead><tr><th>Pack</th><th>Boxes</th><th>Revenue</th></tr></thead><tbody><?php foreach($packs as $p):?><tr><td><?=(int)$p['pack_size']?> pack</td><td><?=(int)$p['boxes']?></td><td><?=money((int)$p['revenue_cents'])?></td></tr><?php endforeach;?></tbody></table></section>
<section><h2>Flavor popularity</h2><table><thead><tr><th>Flavor</th><th>Donuts</th></tr></thead><tbody><?php foreach($flavors as $f):?><tr><td><?=htmlspecialchars($f['name'])?></td><td><?=(int)$f['units']?></td></tr><?php endforeach;?></tbody></table></section></div>
<section><h2>Order status</h2><table><thead><tr><th>Status</th><th>Orders</th><th>Revenue</th></tr></thead><tbody><?php foreach($statuses as $s):?><tr><td><?=htmlspecialchars($s['status'])?></td><td><?=(int)$s['orders']?></td><td><?=money((int)$s['revenue_cents'])?></td></tr><?php endforeach;?></tbody></table></section>
</main></body></html>
