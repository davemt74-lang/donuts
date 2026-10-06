<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{Database,FinishedGoodsAgingService,JobMonitorService,ObservabilityService};

$db=Database::connection();$monitor=new JobMonitorService($db);
$runId=$monitor->start('finished-goods-aging','Finished-goods expiry and aging control',(int)env('JOB_FINISHED_GOODS_AGING_INTERVAL_MINUTES','60'));
try{
    $svc=new FinishedGoodsAgingService($db);
    $held=$svc->holdExpired();$summary=$svc->summary();
    $obs=new ObservabilityService($db);
    if($summary['expired_units']>0){
        $obs->record('warning','finished_goods_expired','Expired finished goods require disposition.',['expired_batches'=>$summary['expired_batches'],'expired_units'=>$summary['expired_units']]);
    }else{$obs->resolveSystemType('finished_goods_expired');}
    if($summary['expiring_units']>0){
        $obs->record('warning','finished_goods_expiring','Finished goods are approaching best-by date.',['expiring_batches'=>$summary['expiring_batches'],'expiring_units'=>$summary['expiring_units'],'warning_days'=>$svc->warningDays()]);
    }else{$obs->resolveSystemType('finished_goods_expiring');}
    $monitor->succeed($runId,'held='.$held.' expired_units='.$summary['expired_units'].' expiring_units='.$summary['expiring_units']);
    echo json_encode(['held'=>$held,'summary'=>$summary],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $e){
    try{$monitor->fail($runId,$e->getMessage());}catch(Throwable){}
    throw $e;
}
