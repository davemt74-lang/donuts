<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use FudgeDonuts\Database;

$db = Database::connection();
$driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
if ($driver !== 'sqlite') {
    fwrite(STDERR, "Initial migration currently targets SQLite. For MySQL, translate INTEGER PRIMARY KEY AUTOINCREMENT to BIGINT AUTO_INCREMENT.\n");
    exit(2);
}
foreach (glob(dirname(__DIR__) . '/database/*.sql') ?: [] as $file) {
    $db->exec((string)file_get_contents($file));
    echo 'Applied ' . basename($file) . PHP_EOL;
}
