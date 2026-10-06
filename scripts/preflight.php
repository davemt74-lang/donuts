<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use FudgeDonuts\PreflightService;

$service=new PreflightService();
$ok=true;
foreach($service->checks() as $check){
    echo ($check['ok']?'[PASS] ':'[FAIL] ').$check['message'].PHP_EOL;
    if(!$check['ok'])$ok=false;
}
exit($ok?0:1);
