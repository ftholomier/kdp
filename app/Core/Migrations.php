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
        self::ensureColumn('projects', 'interior_colors', 'VARCHAR(120) NULL AFTER layout_options');
        self::ensureColumn('chapters', 'role', "ENUM('chapter','intro','conclusion') NOT NULL DEFAULT 'chapter' AFTER num");
        self::ensureColumn('covers', 'layout_json', 'MEDIUMTEXT NULL');
        self::ensureColumn('covers', 'layout_back_json', 'MEDIUMTEXT NULL');
        self::ensureColumn('kdp_meta', 'asin', 'VARCHAR(20) NULL AFTER isbn');
        self::ensureColumn('kdp_meta', 'aplus_json', 'MEDIUMTEXT NULL');
        self::ensureColumn('projects', 'translate_from', 'INT UNSIGNED NULL');
        self::ensureColumn('projects', 'translate_lang', 'VARCHAR(30) NULL');
        // Langue du livre : gouverne les métadonnées Amazon ET les libellés
        // composés dans le PDF/ePub (sommaire, encadrés, pages de fin).
        self::ensureColumn('projects', 'lang', "VARCHAR(5) NULL AFTER tone");
        // Polices de l'intérieur choisies à l'étape 07 : {"title":"…","body":"…"}
        self::ensureColumn('projects', 'interior_fonts', 'VARCHAR(80) NULL AFTER interior_colors');
        // CONSIGNES DE L'AUTEUR : réinjectées en tête de chaque appel à l'IA,
        // prioritaires sur le concept et sur le plan importé (App\Services\Brief).
        self::ensureColumn('projects', 'brief', 'MEDIUMTEXT NULL AFTER idea');
        // Pages par chapitre voulues par l'auteur (NULL = calcul automatique).
        self::ensureColumn('projects', 'pages_per_chapter', 'SMALLINT UNSIGNED NULL AFTER pages');
        // Mise en page générée puis appliquée : {"name":"…","why":"…"} — les
        // réglages eux-mêmes vivent dans interior_theme/layout_options/fonts.
        self::ensureColumn('projects', 'layout_recipe', 'TEXT NULL AFTER interior_fonts');
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
