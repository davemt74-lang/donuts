<?php
declare(strict_types=1);
$root=dirname(__DIR__);
putenv('APP_ENV=development');
putenv('DB_DSN=sqlite::memory:');
require $root.'/src/bootstrap.php';

use FudgeDonuts\{Database,PreflightService};

$db=Database::connection();
foreach(glob($root.'/database/*.sql')?:[] as $file)$db->exec((string)file_get_contents($file));
foreach(glob($root.'/database/*.sql')?:[] as $file)$db->exec((string)file_get_contents($file));

$service=new PreflightService();
$checks=$service->checks();
$failed=array_values(array_filter($checks,fn($c)=>!$c['ok']));
assert($failed===[]);
assert($service->healthy()===true);

assert(is_file($root.'/public/robots.txt'));
assert(is_file($root.'/DEPLOYMENT.md'));

echo "Section 18 checks passed\n";
