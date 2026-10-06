<?php
declare(strict_types=1);
$root=dirname(__DIR__);require $root.'/src/PerformanceService.php';
use FudgeDonuts\PerformanceService;

$p=PerformanceService::policy('GET','/flavor.php?slug=smores',false);
assert($p['public']===true);
assert(str_contains($p['cache_control'],'max-age=300'));
assert(PerformanceService::policy('HEAD','/faq.php',false)['public']===true);
assert(PerformanceService::policy('GET','/cart.php',false)['public']===false);
assert(PerformanceService::policy('GET','/flavor.php',true)['public']===false);
assert(PerformanceService::policy('POST','/faq.php',false)['public']===false);

$tmp=$root.'/public/assets/section51-test.css';
file_put_contents($tmp,'x');
$url=PerformanceService::assetUrl('/assets/section51-test.css',$root);
assert(str_starts_with($url,'/assets/section51-test.css?v='));
unlink($tmp);

echo "Section 51 checks passed\n";
