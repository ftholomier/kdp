<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Migrations automatiques : colonnes ajoutées après la première installation.
 * Idempotent et silencieux — exécuté une fois par requête authentifiée.
 */
final class Migrations
{
    public static function run(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        self::ensureColumn('projects', 'final_pages', 'SMALLINT UNSIGNED NULL AFTER pages');
        self::ensureColumn('projects', 'interior_theme', "VARCHAR(20) NOT NULL DEFAULT 'editorial' AFTER trim_format");
        self::ensureColumn('projects', 'layout_options', 'TEXT NULL AFTER interior_theme');
        self::ensureColumn('chapters', 'role', "ENUM('chapter','intro','conclusion') NOT NULL DEFAULT 'chapter' AFTER num");
        self::ensureColumn('covers', 'layout_json', 'MEDIUMTEXT NULL');
    }

    private static function ensureColumn(string $table, string $column, string $definition): void
    {
        try {
            Db::one("SELECT {$column} FROM {$table} LIMIT 1");
        } catch (\PDOException $e) {
            try {
                Db::pdo()->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
            } catch (\Throwable $inner) {
                // concurrence : une autre requête a pu l'ajouter
            }
        }
    }
}
