<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Réglages éditables depuis l'interface (écran « Connecteurs ») :
 * clés API et paramètres de connecteurs, stockés en BDD.
 *
 * Priorité : valeur saisie dans l'interface (BDD) > config/config.php.
 * Seules les clés listées dans ALLOWED sont acceptées.
 */
final class Settings
{
    public const ALLOWED = [
        'gemini.api_key',
        'gemini.model_fast',
        'gemini.model_pro',
        'canopy.api_key',
        'canopy.domain',
        'cron.secret',
        'notify.email',
    ];

    private static ?array $cache = null;

    /** Valeur effective : BDD si non vide, sinon fichier de config. */
    public static function get(string $name, mixed $default = null): mixed
    {
        $stored = self::stored();
        if (($stored[$name] ?? '') !== '') {
            return $stored[$name];
        }
        return Config::get($name, $default);
    }

    /** Provenance de la valeur effective : 'interface', 'fichier' ou null. */
    public static function source(string $name): ?string
    {
        if ((self::stored()[$name] ?? '') !== '') {
            return 'interface';
        }
        return trim((string) Config::get($name, '')) !== '' ? 'fichier' : null;
    }

    public static function set(string $name, string $value): void
    {
        if (!in_array($name, self::ALLOWED, true)) {
            throw new \RuntimeException('Réglage non autorisé : ' . $name);
        }
        self::ensureTable();
        Db::run(
            'INSERT INTO settings (name, value, updated_at) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)',
            [$name, $value, Db::now()]
        );
        self::$cache = null;
    }

    /** Fin masquée d'une clé pour l'affichage (jamais la clé complète). */
    public static function masked(string $name): string
    {
        $value = trim((string) self::get($name, ''));
        if ($value === '') {
            return '';
        }
        return '••••' . substr($value, -4);
    }

    private static function stored(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        try {
            $rows = Db::all('SELECT name, value FROM settings');
        } catch (\PDOException $e) {
            // Table absente (installation antérieure au connecteur) : on la crée.
            try {
                self::ensureTable();
                $rows = Db::all('SELECT name, value FROM settings');
            } catch (\Throwable $inner) {
                $rows = [];
            }
        }
        self::$cache = array_column($rows, 'value', 'name');
        return self::$cache;
    }

    private static function ensureTable(): void
    {
        Db::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS settings (
                name       VARCHAR(64) NOT NULL,
                value      TEXT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
