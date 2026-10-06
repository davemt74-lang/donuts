<?php
declare(strict_types=1);
$root=dirname(__DIR__);$tmp=$root.'/storage/section39-test.sqlite';@unlink($tmp);putenv('DB_DSN=sqlite:storage/section39-test.sqlite');require $root.'/src/bootstrap.php';

use FudgeDonuts\{BackupService,Database};

$db=Database::connection();$db->exec('CREATE TABLE demo(id INTEGER PRIMARY KEY,value TEXT NOT NULL)');$db->exec("INSERT INTO demo(value) VALUES('first')");
$svc=new BackupService($db,$root);$meta=$svc->create('test');assert(is_file($meta['path']));assert(strlen($meta['sha256'])===64);assert($svc->verify($meta['path'])['ok']===true);
$db->exec("INSERT INTO demo(value) VALUES('second')");$pdo=new PDO('sqlite:'.$meta['path']);assert((int)$pdo->query('SELECT COUNT(*) FROM demo')->fetchColumn()===1);$pdo=null;
$rows=$svc->list();assert(count($rows)>=1);assert($svc->resolveBackup($meta['file'])===$meta['path']);
$bad=false;try{$svc->resolveBackup('../store.sqlite');}catch(InvalidArgumentException){$bad=true;}assert($bad);
@unlink($meta['path']);@unlink($meta['path'].'.json');Database::disconnect();@unlink($tmp);@unlink($tmp.'-wal');@unlink($tmp.'-shm');
echo "Section 40 checks passed\n";
