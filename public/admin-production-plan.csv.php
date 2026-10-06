<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{Database,FulfillmentOperationsService,ProductionPlanningService};
require_admin_roles(['super_admin','admin','fulfillment']);

$history=(int)($_GET['history']??env('PRODUCTION_HISTORY_DAYS','28'));
$days=(int)($_GET['days']??7);
$safety=(int)($_GET['safety']??env('PRODUCTION_SAFETY_DAYS','2'));
$plan=(new ProductionPlanningService(Database::connection()))->forecast($history,$days,$safety);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="fudge-donuts-production-plan-'.gmdate('Ymd-His').'.csv"');
$out=fopen('php://output','wb');
fputcsv($out,['Flavor','Risk','Recent units','Daily velocity','Forecast units','Safety units','On hand','Reserved','Available','Days cover','Suggested prep']);
foreach($plan['rows'] as $row){
    fputcsv($out,[
        FulfillmentOperationsService::csvCell($row['name']),
        $row['risk'],$row['recent_units'],$row['daily_velocity'],$row['forecast_units'],$row['safety_units'],
        $row['stock_on_hand'],$row['reserved'],$row['available']===null?'Untracked':$row['available'],
        $row['days_cover']===null?'':$row['days_cover'],$row['suggested_prep']
    ]);
}
fclose($out);exit;
