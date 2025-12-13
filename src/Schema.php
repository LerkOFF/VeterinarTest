<?php
declare(strict_types=1);

namespace App;

final class Schema
{
    public static function ensure(): void
    {
        $pdo = Db::pdo();

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS clients (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                full_name TEXT NOT NULL,
                address TEXT NOT NULL,
                phone TEXT NULL,
                notes TEXT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
                updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
            )'
        );

        // Остальные таблицы добавим следующим шагом (питомцы/визиты/лекарства/назначения),
        // чтобы шли строго по этапам и проще проверять.
    }
}
