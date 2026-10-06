<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{BatchTraceabilityService,Database,FulfillmentOperationsService};
require_admin_roles(['super_admin','admin','fulfillment']);

$id=(int)($_GET['id']??0);
try{$batch=(new BatchTraceabilityService(Database::connection()))->batch($id);}
catch(Throwable){http_response_code(404);exit('Production batch not found.');}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="batch-'.rawurlencode((string)$batch['batch_code']).'-affected-orders.csv"');
$out=fopen('php://output','wb');
fputcsv($out,['Batch','Batch Status','Recall Reason','Order','Order Status','First Name','Last Name','Email','Phone','Fulfillment','Created']);
foreach($batch['affected_orders'] as $row){
    fputcsv($out,[
        FulfillmentOperationsService::csvCell((string)$batch['batch_code']),
        FulfillmentOperationsService::csvCell((string)$batch['status']),
        FulfillmentOperationsService::csvCell((string)$batch['recall_reason']),
        FulfillmentOperationsService::csvCell((string)$row['order_number']),
        FulfillmentOperationsService::csvCell((string)$row['status']),
        FulfillmentOperationsService::csvCell((string)$row['first_name']),
        FulfillmentOperationsService::csvCell((string)$row['last_name']),
        FulfillmentOperationsService::csvCell((string)$row['email']),
        FulfillmentOperationsService::csvCell((string)$row['phone']),
        FulfillmentOperationsService::csvCell((string)$row['fulfillment_type']),
        FulfillmentOperationsService::csvCell((string)$row['created_at']),
    ]);
}
fclose($out);exit;
