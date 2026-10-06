<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{Database,ObservabilityService};

header('Content-Type: application/json; charset=UTF-8');
$status='unhealthy';$database='down';
try{
    $db=Database::connection();$db->query('SELECT 1');$database='ok';
    $ops=(new ObservabilityService($db))->health();
    $status=(string)$ops['status'];
}catch(Throwable $e){
    ObservabilityService::captureThrowable($e,dirname(__DIR__),'health_check_failure');
}
http_response_code($status==='unhealthy'?503:200);
echo json_encode([
    'status'=>$status,
    'database'=>$database,
    'request_id'=>ObservabilityService::requestId(),
    'time'=>gmdate('c'),
],JSON_THROW_ON_ERROR);
