<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('APP_ENV=development');putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';

use FudgeDonuts\{Database,ReleaseAuditService};

$db=Database::connection();
$files=glob($root.'/database/*.sql')?:[];
sort($files);
foreach($files as $file)$db->exec((string)file_get_contents($file));
foreach($files as $file)$db->exec((string)file_get_contents($file));

$audit=new ReleaseAuditService($db,$root);
assert($audit->healthy()===true);
$requiredFailures=array_values(array_filter($audit->audit(),fn($c)=>($c['required']??true)&&!$c['ok']));
assert($requiredFailures===[]);
assert(is_file($root.'/.github/workflows/release-package.yml'));
echo "Section 30 checks passed\n";
