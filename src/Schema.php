<?php
declare(strict_types=1);

namespace App;

final class Schema
{
    public static function ensure(): void
    {
        $pdo = Db::pdo();

        // Clients: обязательное только full_name
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS clients (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                full_name TEXT NOT NULL,
                address TEXT NULL,
                phone TEXT NULL,
                notes TEXT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
                updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
            )'
        );

        // Pets: обязательное только name (кличка)
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS pets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                client_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                species TEXT NULL,
                breed TEXT NULL,
                birth_date TEXT NULL,
                medications TEXT NULL,
                notes TEXT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
                updated_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
                FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT
            )'
        );

        // Индекс для быстрых выборок питомцев по клиенту
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pets_client_id ON pets(client_id)');
    }
}
