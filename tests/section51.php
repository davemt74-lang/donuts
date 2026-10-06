<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$tmp=$root.'/storage/section51.sqlite';
@unlink($tmp);@unlink($tmp.'-wal');@unlink($tmp.'-shm');
putenv('DB_DSN=sqlite:storage/section51.sqlite');
require $root.'/src/bootstrap.php';

use FudgeDonuts\{Database,PerformanceService};

$p=PerformanceService::policy('GET','/flavor.php?slug=smores',false);
assert($p['public']===true);
assert(str_contains($p['cache_control'],'max-age=300'));
assert(PerformanceService::policy('HEAD','/faq.php',false)['public']===true);
assert(PerformanceService::policy('GET','/cart.php',false)['public']===false);
assert(PerformanceService::policy('GET','/flavor.php',true)['public']===false);
assert(PerformanceService::policy('POST','/faq.php',false)['public']===false);

assert(PerformanceService::canSkipSession('GET','/flavor.php?slug=smores',false)===true);
assert(PerformanceService::canSkipSession('HEAD','/story.php',false)===true);
assert(PerformanceService::canSkipSession('GET','/story.php',true)===false);
assert(PerformanceService::canSkipSession('GET','/cart.php',false)===false);

$asset=$root.'/public/assets/section51-test.css';
file_put_contents($asset,'x');
$url=PerformanceService::assetUrl('/assets/section51-test.css',$root);
assert(str_starts_with($url,'/assets/section51-test.css?v='));
unlink($asset);

$db=Database::connection();
assert((int)$db->query('PRAGMA busy_timeout')->fetchColumn()>=5000);
assert(strtolower((string)$db->query('PRAGMA journal_mode')->fetchColumn())==='wal');
assert((int)$db->query('PRAGMA cache_size')->fetchColumn()<=-20000);
$sync=(int)$db->query('PRAGMA synchronous')->fetchColumn();
assert(in_array($sync,[1,2],true));

$critical=['index.php','cart.php','builder.php','checkout.php','checkout-review.php','contact.php','faq.php','account.php','admin.php','admin-orders.php'];
foreach($critical as $file){
    $html=(string)file_get_contents($root.'/public/'.$file);
    assert(str_contains($html,"asset_url('/assets/app.css')"),$file.' must version shared CSS');
}
assert(str_contains((string)file_get_contents($root.'/public/builder.php'),"asset_url('/assets/builder.js')"));

$ht=(string)file_get_contents($root.'/public/.htaccess');
assert(str_contains($ht,'mod_deflate.c'));
assert(str_contains($ht,'max-age=31536000'));
assert(str_contains($ht,'max-age=604800'));

Database::disconnect();
@unlink($tmp);@unlink($tmp.'-wal');@unlink($tmp.'-shm');
echo "Section 51 checks passed\n";
