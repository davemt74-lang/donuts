<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{Database,MigrationService,ObservabilityService,ReleaseAuditService};

$root=dirname(__DIR__);$db=Database::connection();$ok=true;$checks=[];
try{
    $migrations=new MigrationService($db,$root.'/database');$migrations->assertClean();
    $checks[]=['name'=>'migrations','ok'=>true,'message'=>'No pending or drifted migrations'];
}catch(Throwable $e){$checks[]=['name'=>'migrations','ok'=>false,'message'=>$e->getMessage()];$ok=false;}

try{
    $integrity=(string)$db->query('PRAGMA integrity_check')->fetchColumn();
    $pass=$integrity==='ok';$checks[]=['name'=>'database_integrity','ok'=>$pass,'message'=>'SQLite integrity: '.$integrity];if(!$pass)$ok=false;
}catch(Throwable $e){$checks[]=['name'=>'database_integrity','ok'=>false,'message'=>$e->getMessage()];$ok=false;}

foreach((new ReleaseAuditService($db,$root))->audit() as $check){
    if(($check['required']??true) && !$check['ok'])$ok=false;
    $checks[]=['name'=>$check['name'],'ok'=>$check['ok'],'message'=>$check['message']];
}

try{
    $health=(new ObservabilityService($db))->health();
    $healthy=$health['status']!=='unhealthy';
    $checks[]=['name'=>'runtime_health','ok'=>$healthy,'message'=>'Operational health: '.$health['status']];
    if(!$healthy)$ok=false;
}catch(Throwable $e){$checks[]=['name'=>'runtime_health','ok'=>false,'message'=>$e->getMessage()];$ok=false;}

foreach($checks as $check)echo '['.($check['ok']?'PASS':'FAIL').'] '.$check['message'].PHP_EOL;

$manifest=[
    'certified_at'=>gmdate('c'),
    'ok'=>$ok,
    'php_version'=>PHP_VERSION,
    'release_id'=>(string)env('RELEASE_ID',''),
    'checks'=>$checks,
];
$path=$root.'/storage/release-certification.json';
file_put_contents($path,json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX);
echo 'Certification: '.$path.PHP_EOL;
exit($ok?0:1);
