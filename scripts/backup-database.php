<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

use FudgeDonuts\{BackupService,Database};

$svc=new BackupService(Database::connection(),dirname(__DIR__));
$meta=$svc->create($argv[1]??'scheduled');
$deleted=$svc->prune((int)env('BACKUP_RETENTION_DAYS','14'),(int)env('BACKUP_MAX_FILES','60'));
echo "Backup created: {$meta['file']}\n";
echo "SHA-256: {$meta['sha256']}\n";
echo "Bytes: {$meta['bytes']}\n";
echo "Pruned: {$deleted}\n";
