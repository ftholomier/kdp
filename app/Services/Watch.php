<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * Veille marché Amazon (tableau de bord Canopy).
 *
 * Principe d'économie du quota gratuit (~100 requêtes/mois) :
 *   - AUCUN rafraîchissement automatique : chaque relevé est un clic
 *     volontaire de l'utilisateur qui consomme 1 crédit Canopy ;
 *   - les relevés sont persistés en base : consulter le tableau de bord
 *     ne coûte jamais rien ;
 *   - chaque relevé archive le précédent → deltas (prix médian, volume
 *     d'avis) entre deux relevés pour lire la tendance de la niche.
 */
final class Watch
{
    public static function list(int $userId): array
    {
        self::ensureTables();
        $rows = Db::all(
            'SELECT * FROM watch_terms WHERE user_id = ? ORDER BY position, id',
            [$userId]
        );
        return array_map(function (array $row) {
            $snapshot = json_decode((string) ($row['snapshot'] ?? ''), true);
            $previous = Db::one(
                'SELECT snapshot FROM watch_history WHERE watch_id = ? ORDER BY id DESC LIMIT 1',
                [(int) $row['id']]
            );
            $previousSnapshot = $previous ? json_decode((string) $previous['snapshot'], true) : null;
            return [
                'id'         => (int) $row['id'],
                'term'       => $row['term'],
                'updated_at' => $row['updated_at'],
                'snapshot'   => is_array($snapshot) ? $snapshot : null,
                'delta'      => self::delta(is_array($snapshot) ? $snapshot : null, is_array($previousSnapshot) ? $previousSnapshot : null),
            ];
        }, $rows);
    }

    public static function add(int $userId, string $term): void
    {
        self::ensureTables();
        $term = mb_substr(trim($term), 0, 120);
        if (mb_strlen($term) < 2) {
            throw new \RuntimeException('Saisissez une niche à suivre (2 caractères minimum).');
        }
        $exists = Db::one(
            'SELECT id FROM watch_terms WHERE user_id = ? AND LOWER(term) = LOWER(?)',
            [$userId, $term]
        );
        if ($exists) {
            throw new \RuntimeException('Cette niche est déjà suivie.');
        }
        $max = Db::one('SELECT COALESCE(MAX(position), 0) AS p FROM watch_terms WHERE user_id = ?', [$userId]);
        Db::run(
            'INSERT INTO watch_terms (user_id, term, position, created_at) VALUES (?,?,?,?)',
            [$userId, $term, (int) $max['p'] + 1, Db::now()]
        );
    }

    public static function remove(int $userId, int $watchId): void
    {
        self::ensureTables();
        Db::run('DELETE FROM watch_terms WHERE id = ? AND user_id = ?', [$watchId, $userId]);
    }

    /**
     * Relevé à la demande (clic utilisateur) : appel Canopy RÉEL (hors cache),
     * archivage du relevé précédent pour les deltas. Consomme 1 crédit.
     */
    public static function refresh(int $userId, int $watchId): array
    {
        self::ensureTables();
        $row = Db::one('SELECT * FROM watch_terms WHERE id = ? AND user_id = ?', [$watchId, $userId]);
        if (!$row) {
            throw new \RuntimeException('Niche suivie introuvable.');
        }
        if (!Canopy::enabled()) {
            throw new \RuntimeException('Connecteur Canopy non configuré — collez votre clé dans ⚡ Connecteurs.');
        }
        $status = Canopy::status();
        if ($status['exhausted']) {
            throw new \RuntimeException('Quota Canopy mensuel atteint (' . $status['used'] . '/' . $status['budget'] . ') — réessayez le mois prochain ou augmentez monthly_budget.');
        }

        $snapshot = Canopy::marketSnapshot((string) $row['term'], true);
        if (!$snapshot) {
            throw new \RuntimeException('Aucun résultat Amazon pour « ' . $row['term'] . ' » — vérifiez le terme ou la clé Canopy.');
        }

        if (!empty($row['snapshot'])) {
            Db::run(
                'INSERT INTO watch_history (watch_id, snapshot, created_at) VALUES (?,?,?)',
                [$watchId, $row['snapshot'], (string) ($row['updated_at'] ?? Db::now())]
            );
            // On borne l'historique à 24 relevés par niche
            Db::run(
                'DELETE FROM watch_history WHERE watch_id = ? AND id NOT IN (
                    SELECT id FROM (SELECT id FROM watch_history WHERE watch_id = ? ORDER BY id DESC LIMIT 24) keep
                )',
                [$watchId, $watchId]
            );
        }
        Db::run(
            'UPDATE watch_terms SET snapshot = ?, updated_at = ? WHERE id = ?',
            [json_encode($snapshot, JSON_UNESCAPED_UNICODE), Db::now(), $watchId]
        );
        return ['snapshot' => $snapshot, 'status' => Canopy::status()];
    }

    /** Passerelle : créer un projet de livre pré-rempli avec la niche suivie. */
    public static function createBook(int $userId, int $watchId): int
    {
        $row = Db::one('SELECT * FROM watch_terms WHERE id = ? AND user_id = ?', [$watchId, $userId]);
        if (!$row) {
            throw new \RuntimeException('Niche suivie introuvable.');
        }
        return Db::insert(
            'INSERT INTO projects (user_id, title, idea, mode, created_at, updated_at) VALUES (?,?,?,?,?,?)',
            [
                $userId,
                mb_substr('Livre — ' . $row['term'], 0, 250),
                'Un livre sur la niche « ' . $row['term'] . ' », calé sur ce qui se vend le mieux sur Amazon.fr dans cette catégorie.',
                'describe',
                Db::now(),
                Db::now(),
            ]
        );
    }

    /**
     * Relevés de veille pertinents pour un texte donné (idée, nom de thème) :
     * source PRIORITAIRE et GRATUITE des analyses (étapes 1 et 2) — vos clics
     * dans le tableau de bord alimentent directement la solution.
     */
    public static function findRelevant(int $userId, string $text, int $max = 2): array
    {
        self::ensureTables();
        $needle = self::normalize($text);
        if ($needle === '') {
            return [];
        }
        $matches = [];
        foreach (Db::all('SELECT term, snapshot, updated_at FROM watch_terms WHERE user_id = ? AND snapshot IS NOT NULL', [$userId]) as $row) {
            $term = self::normalize((string) $row['term']);
            if ($term === '') {
                continue;
            }
            // Correspondance : terme contenu dans le texte, ou majorité de ses
            // mots significatifs présents dans le texte.
            $score = 0;
            if (str_contains($needle, $term)) {
                $score = 100;
            } else {
                $words = array_filter(explode(' ', $term), fn ($w) => mb_strlen($w) > 3);
                if ($words) {
                    $hits = count(array_filter($words, fn ($w) => str_contains($needle, $w)));
                    $ratio = $hits / count($words);
                    if ($ratio >= 0.5) {
                        $score = (int) round($ratio * 90);
                    }
                }
            }
            if ($score > 0) {
                $snapshot = json_decode((string) $row['snapshot'], true);
                if (is_array($snapshot)) {
                    $snapshot['watch_date'] = (string) ($row['updated_at'] ?? '');
                    $matches[] = ['score' => $score, 'snapshot' => $snapshot];
                }
            }
        }
        usort($matches, fn ($a, $b) => $b['score'] <=> $a['score']);
        return array_column(array_slice($matches, 0, $max), 'snapshot');
    }

    private static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $text) ?? '');
    }

    // ── Interne ────────────────────────────────────────────────────────────

    private static function delta(?array $current, ?array $previous): array
    {
        if (!$current || !$previous) {
            return ['price' => null, 'reviews' => null];
        }
        $price = null;
        if (isset($current['median_price'], $previous['median_price'])
            && $current['median_price'] !== null && $previous['median_price'] !== null) {
            $price = round((float) $current['median_price'] - (float) $previous['median_price'], 2);
        }
        $reviews = null;
        if (isset($current['total_reviews'], $previous['total_reviews']) && (int) $previous['total_reviews'] > 0) {
            $reviews = (int) round(
                ((int) $current['total_reviews'] - (int) $previous['total_reviews'])
                / (int) $previous['total_reviews'] * 100
            );
        }
        return ['price' => $price, 'reviews' => $reviews];
    }

    private static function ensureTables(): void
    {
        Db::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS watch_terms (
                id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id    INT UNSIGNED NOT NULL,
                term       VARCHAR(120) NOT NULL,
                position   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                snapshot   MEDIUMTEXT NULL,
                updated_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_watch_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        Db::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS watch_history (
                id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                watch_id   INT UNSIGNED NOT NULL,
                snapshot   MEDIUMTEXT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_history_watch (watch_id, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
