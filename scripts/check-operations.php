<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{AnalyticsService,Database,JobMonitorService,LoyaltyService,NotificationService,ObservabilityService};

$db=Database::connection();$monitor=new JobMonitorService($db);$runId=$monitor->start('operations','Operational health and alert checks',(int)env('JOB_OPERATIONS_INTERVAL_MINUTES','5'));$obs=new ObservabilityService($db);$health=$obs->health();
$alertEmail=trim((string)env('ALERT_EMAIL',''));
$severity=$health['status']==='unhealthy'?'critical':($health['status']==='degraded'?'warning':'info');

if($health['status']!=='ok'){
    $message='Store operational health is '.$health['status'].'.';
    $id=$obs->record($severity,'operations_health',$message,$health);
    if($alertEmail!==''&&filter_var($alertEmail,FILTER_VALIDATE_EMAIL)){
        $key='ops-health:'.$id.':'.gmdate('Y-m-d-H');
        $body=$message."\n\n".json_encode($health,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
        (new NotificationService($db))->queue($alertEmail,'Fudge Donuts operational alert',$body,$key);
    }
}else{
    $obs->resolveSystemType('operations_health');
}

$pruned=$obs->prune((int)env('OBSERVABILITY_RETENTION_DAYS','90'));$abandoned=$monitor->abandonStuckRuns();$analyticsPruned=0;$loyaltyReconciled=0;
try{$loyaltyReconciled=(new LoyaltyService($db))->reconcileSucceededRefunds(100);}catch(Throwable $e){ObservabilityService::captureThrowable($e,dirname(__DIR__),'loyalty_refund_reconciliation_failure');}
if((env('ANALYTICS_ENABLED','0')??'0')==='1'){
    try{$analyticsPruned=(new AnalyticsService($db))->prune((int)env('ANALYTICS_RETENTION_DAYS','180'));}catch(Throwable){}
}
$monitor->succeed($runId,'health='.$health['status'].' pruned='.$pruned.' analytics_pruned='.$analyticsPruned.' loyalty_reconciled='.$loyaltyReconciled.' abandoned='.$abandoned);
echo json_encode(['health'=>$health,'pruned'=>$pruned,'analytics_pruned'=>$analyticsPruned,'loyalty_reconciled'=>$loyaltyReconciled,'abandoned_runs'=>$abandoned],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
exit($health['status']==='unhealthy'?2:0);
