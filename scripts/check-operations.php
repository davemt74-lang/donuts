<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{Database,NotificationService,ObservabilityService};

$db=Database::connection();$obs=new ObservabilityService($db);$health=$obs->health();
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
}

$pruned=$obs->prune((int)env('OBSERVABILITY_RETENTION_DAYS','90'));
echo json_encode(['health'=>$health,'pruned'=>$pruned],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
exit($health['status']==='unhealthy'?2:0);
