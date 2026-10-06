<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{BackupService,Database,JobMonitorService};

$db=Database::connection();$monitor=new JobMonitorService($db);
$runId=$monitor->start('backup','Verified SQLite database backup',(int)env('JOB_BACKUP_INTERVAL_MINUTES','1440'));
try{
    $svc=new BackupService($db,dirname(__DIR__));
    $meta=$svc->create($argv[1]??'scheduled');
    $deleted=$svc->prune((int)env('BACKUP_RETENTION_DAYS','14'),(int)env('BACKUP_MAX_FILES','60'));
    $monitor->succeed($runId,"file={$meta['file']} bytes={$meta['bytes']} pruned={$deleted}");
    echo "Backup created: {$meta['file']}\nSHA-256: {$meta['sha256']}\nBytes: {$meta['bytes']}\nPruned: {$deleted}\n";
}catch(Throwable $e){
    $monitor->fail($runId,$e->getMessage());throw $e;
}
