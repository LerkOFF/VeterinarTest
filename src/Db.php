<?php
declare(strict_types=1);

namespace App;

use PDO;

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $dbPath = dirname(__DIR__) . '/var/vetclinic.sqlite';
        $dsn = 'sqlite:' . $dbPath;

        $pdo = new PDO($dsn);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Включаем внешние ключи в SQLite
        $pdo->exec('PRAGMA foreign_keys = ON;');

        self::$pdo = $pdo;
        return $pdo;
    }
}
