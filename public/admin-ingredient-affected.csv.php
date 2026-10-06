<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{Database,FulfillmentOperationsService,IngredientTraceabilityService};
require_admin_roles(['super_admin','admin','fulfillment']);

$id=(int)($_GET['id']??0);
try{$svc=new IngredientTraceabilityService(Database::connection());$lot=$svc->lot($id);}
catch(Throwable){http_response_code(404);exit('Ingredient lot not found.');}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="ingredient-lot-'.rawurlencode((string)$lot['supplier_lot_code']).'-affected-orders.csv"');
$out=fopen('php://output','wb');
fputcsv($out,['Ingredient','Supplier','Supplier Lot','Lot Status','Recall Reason','Order','Order Status','First Name','Last Name','Email','Created']);
foreach($lot['orders'] as $row){
    fputcsv($out,[
        FulfillmentOperationsService::csvCell((string)$lot['ingredient_name']),
        FulfillmentOperationsService::csvCell((string)$lot['supplier_name']),
        FulfillmentOperationsService::csvCell((string)$lot['supplier_lot_code']),
        FulfillmentOperationsService::csvCell((string)$lot['status']),
        FulfillmentOperationsService::csvCell((string)$lot['recall_reason']),
        FulfillmentOperationsService::csvCell((string)$row['order_number']),
        FulfillmentOperationsService::csvCell((string)$row['status']),
        FulfillmentOperationsService::csvCell((string)$row['first_name']),
        FulfillmentOperationsService::csvCell((string)$row['last_name']),
        FulfillmentOperationsService::csvCell((string)$row['email']),
        FulfillmentOperationsService::csvCell((string)$row['created_at']),
    ]);
}
fclose($out);exit;
