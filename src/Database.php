<?php
declare(strict_types=1);

namespace FudgeDonuts;

use PDO;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo) return self::$pdo;

        $dsn = \env('DB_DSN', 'sqlite:' . dirname(__DIR__) . '/storage/store.sqlite');
        if ((string)$dsn !== 'sqlite::memory:' && str_starts_with((string)$dsn, 'sqlite:') && !str_starts_with((string)$dsn, 'sqlite:/')) {
            $dsn = 'sqlite:' . dirname(__DIR__) . '/' . substr((string)$dsn, 7);
        }
        self::$pdo = new PDO((string)$dsn, \env('DB_USER', '') ?? '', \env('DB_PASS', '') ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        if (str_starts_with((string)$dsn, 'sqlite:')) self::$pdo->exec('PRAGMA foreign_keys = ON');
        return self::$pdo;
    }

    public static function disconnect(): void
    {
        self::$pdo=null;
    }
}
