<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{BackupService,Database,MigrationService};

$root=dirname(__DIR__);$storage=$root.'/storage';
if(!is_dir($storage) && !mkdir($storage,0775,true) && !is_dir($storage)) throw new RuntimeException('Unable to create storage directory.');

$lockPath=$storage.'/migrate.lock';$lock=fopen($lockPath,'c+');
if($lock===false || !flock($lock,LOCK_EX|LOCK_NB)){fwrite(STDERR,"Another migration process is already running.\n");exit(4);}

try{
    $db=Database::connection();
    if($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite'){
        fwrite(STDERR,"The certified migration runtime is SQLite.\n");exit(2);
    }

    $svc=new MigrationService($db,$root.'/database');
    $status=$svc->status();

    if(in_array('--status',$argv,true)){
        foreach($status['migrations'] as $row) echo strtoupper($row['state']).' '.$row['file'].PHP_EOL;
        echo "Pending: {$status['pending']} · Drift: {$status['drift']} · Applied: {$status['applied']}\n";
        exit($status['drift']>0?3:0);
    }

    if($status['drift']>0){
        fwrite(STDERR,"Migration drift detected. Refusing to modify the database.\n");
        exit(3);
    }

    if($status['pending']>0 && (env('APP_ENV','development')??'development')==='production'){
        try{
            $backup=(new BackupService($db,$root))->create('pre-migration');
            echo 'Pre-migration backup: '.$backup['file'].PHP_EOL;
        }catch(Throwable $e){
            fwrite(STDERR,'Pre-migration backup failed: '.$e->getMessage().PHP_EOL);
            exit(5);
        }
    }

    $applied=$svc->applyPending();
    foreach($applied as $row) echo 'Applied '.$row['file'].' ('.$row['duration_ms'].' ms)'.PHP_EOL;
    if(!$applied) echo "No pending migrations.\n";
    $svc->assertClean();
}finally{
    if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
}
