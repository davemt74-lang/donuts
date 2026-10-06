<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{Database,ReportingService};
if(empty($_SESSION['admin'])){http_response_code(403);exit('Forbidden');}
header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="fudge-donuts-orders.csv"');
$out=fopen('php://output','wb');$rows=(new ReportingService(Database::connection()))->csvRows();
fputcsv($out,['Order','Created','Status','Email','Fulfillment','Subtotal','Discount','Shipping','Tax','Total']);
foreach($rows as $r)fputcsv($out,[$r['order_number'],$r['created_at'],$r['status'],$r['email'],$r['fulfillment_type'],$r['subtotal_cents'],$r['discount_cents'],$r['shipping_cents'],$r['tax_cents'],$r['total_cents']]);
fclose($out);
