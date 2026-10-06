<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{BackupService,Database};

$args=[];
foreach(array_slice($argv,1) as $arg){
    if(str_starts_with($arg,'--') && str_contains($arg,'=')){[$k,$v]=explode('=',substr($arg,2),2);$args[$k]=$v;}
}
$file=(string)($args['file']??'');$confirm=(string)($args['confirm']??'');
if($file==='' || $confirm!=='RESTORE'){
    fwrite(STDERR,"Usage: php scripts/restore-database.php --file=store-...sqlite --confirm=RESTORE\n");
    exit(2);
}

$root=dirname(__DIR__);$db=Database::connection();$svc=new BackupService($db,$root);
$source=$svc->resolveBackup($file);$check=$svc->verify($source);
if(!$check['ok']){fwrite(STDERR,"Backup integrity check failed: {$check['message']}\n");exit(3);}

$current=$svc->databasePath();
$pre=$svc->create('pre-restore');
$lock=$root.'/storage/maintenance.lock';
file_put_contents($lock,"database restore in progress\n",LOCK_EX);

try{
    $db->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    Database::disconnect();
    $tmp=$current.'.restore-'.bin2hex(random_bytes(4));
    if(!copy($source,$tmp)) throw new RuntimeException('Unable to stage restore database.');
    $tmpCheck=(new BackupService(new PDO('sqlite:'.$tmp),$root))->verify($tmp);
    if(!$tmpCheck['ok']) throw new RuntimeException('Staged restore failed integrity verification.');
    $old=$current.'.pre-restore';
    @unlink($old);
    if(is_file($current) && !rename($current,$old)) throw new RuntimeException('Unable to move current database aside.');
    if(!rename($tmp,$current)){
        if(is_file($old)) rename($old,$current);
        throw new RuntimeException('Unable to activate restored database.');
    }
    @unlink($current.'-wal');@unlink($current.'-shm');
    $verifyPdo=new PDO('sqlite:'.$current,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $result=(string)$verifyPdo->query('PRAGMA integrity_check')->fetchColumn();$verifyPdo=null;
    if($result!=='ok'){
        @unlink($current);
        if(is_file($old)) rename($old,$current);
        throw new RuntimeException('Restored database failed final integrity check.');
    }
    @unlink($old);
    echo "Restore complete from {$file}\n";
    echo "Pre-restore backup: {$pre['file']}\n";
}finally{
    @unlink($lock);
}
