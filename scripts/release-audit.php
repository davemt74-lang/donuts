<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{Database,PreflightService,ReleaseAuditService};

$root=dirname(__DIR__);
$ok=true;

echo "Fudge Donuts release audit\n";
echo "=========================\n";

foreach((new PreflightService())->checks() as $check){
    $status=$check['ok']?'PASS':'FAIL';
    echo "[{$status}] {$check['message']}\n";
    if(!$check['ok'])$ok=false;
}

foreach((new ReleaseAuditService(Database::connection(),$root))->audit() as $check){
    $required=$check['required']??true;
    $status=$check['ok']?'PASS':($required?'FAIL':'WARN');
    echo "[{$status}] {$check['message']}\n";
    if($required && !$check['ok'])$ok=false;
}

exit($ok?0:1);
