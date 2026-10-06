<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\{Database,FulfillmentOperationsService};
require_admin_roles(['super_admin','admin','fulfillment']);

$type=(string)($_GET['type']??'shipping');$status=trim((string)($_GET['status']??'ready'));$svc=new FulfillmentOperationsService(Database::connection());
try{$rows=$type==='pickup'?$svc->pickupRows($status?:null):($type==='shipping'?$svc->shippingRows($status?:null):throw new InvalidArgumentException('Invalid fulfillment type.'));}
catch(Throwable $e){http_response_code(400);exit('Invalid fulfillment export filter.');}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="fudge-donuts-'.$type.'-'.($status?:'all').'-'.gmdate('Ymd-His').'.csv"');
$out=fopen('php://output','wb');
fputcsv($out,['Order','Created','Status','First Name','Last Name','Email','Address 1','Address 2','City','State/Region','Postal Code','Country','Phone','Method','Total']);
foreach($rows as $row)fputcsv($out,[$row['order_number'],$row['created_at'],$row['status'],$row['first_name'],$row['last_name'],$row['email'],$row['line1'],$row['line2'],$row['city'],$row['region'],$row['postal_code'],$row['country'],$row['phone'],$row['fulfillment_name'],number_format(((int)$row['total_cents'])/100,2,'.','')]);
fclose($out);exit;
