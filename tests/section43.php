<?php
declare(strict_types=1);
$root=dirname(__DIR__);putenv('DB_DSN=sqlite::memory:');require $root.'/src/bootstrap.php';
use FudgeDonuts\{Database,MigrationService};

$dir=$root.'/storage/section43-migrations';if(!is_dir($dir))mkdir($dir,0770,true);
foreach(glob($dir.'/*.sql')?:[] as $f)unlink($f);
file_put_contents($dir.'/001_first.sql',"CREATE TABLE alpha(id INTEGER PRIMARY KEY,value TEXT);\n");
file_put_contents($dir.'/002_second.sql',"CREATE TABLE beta(id INTEGER PRIMARY KEY,alpha_id INTEGER);\n");
$db=Database::connection();$m=new MigrationService($db,$dir);
$status=$m->status();assert($status['pending']===2);assert($status['drift']===0);
$applied=$m->applyPending();assert(count($applied)===2);assert($m->status()['pending']===0);$m->assertClean();
assert(count($m->applyPending())===0);
file_put_contents($dir.'/001_first.sql',"CREATE TABLE alpha(id INTEGER PRIMARY KEY,value TEXT,changed TEXT);\n");
$status=$m->status();assert($status['drift']===1);
$blocked=false;try{$m->applyPending();}catch(RuntimeException){$blocked=true;}assert($blocked);
file_put_contents($dir.'/001_first.sql',"CREATE TABLE alpha(id INTEGER PRIMARY KEY,value TEXT);\n");unlink($dir.'/002_second.sql');
assert($m->status()['drift']===1);
foreach(glob($dir.'/*.sql')?:[] as $f)unlink($f);@rmdir($dir);
echo "Section 43 checks passed\n";
