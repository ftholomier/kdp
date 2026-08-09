<?php
declare(strict_types=1);

namespace App\Core;

final class Util
{
    /** Extraction robuste d'un objet JSON depuis une réponse de modèle. */
    public static function extractJson(string $text): array
    {
        $candidates = [];
        $trimmed = trim($text);
        $candidates[] = $trimmed;

        // Blocs ```json ... ```
        if (preg_match('/```(?:json)?\s*(.+?)```/s', $trimmed, $m)) {
            $candidates[] = trim($m[1]);
        }
        // Du premier { au dernier }
        $start = strpos($trimmed, '{');
        $end   = strrpos($trimmed, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $candidates[] = substr($trimmed, $start, $end - $start + 1);
        }

        foreach ($candidates as $candidate) {
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }
            // Virgules terminales tolérées
            $fixed = preg_replace('/,\s*([}\]])/', '$1', $candidate);
            $decoded = json_decode((string) $fixed, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        throw new \RuntimeException('Réponse du modèle illisible (JSON attendu).');
    }

    public static function wordCount(string $text): int
    {
        $clean = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
        return $clean === '' ? 0 : count(preg_split('/\s+/u', $clean) ?: []);
    }

    /** Formatage nombre à la française : 12 480 */
    public static function nf(int|float $n): string
    {
        return number_format((float) $n, 0, ',', ' ');
    }

    public static function slug(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
        return trim(substr($text, 0, 80), '-') ?: 'livre';
    }

    /** Journal de rédaction (affiché en direct dans l'étape 5). */
    public static function journal(int $projectId, string $level, string $message): void
    {
        Db::run(
            'INSERT INTO journal (project_id, level, message, created_at) VALUES (?,?,?,?)',
            [$projectId, $level, mb_substr($message, 0, 490), Db::now()]
        );
    }

    /** Découpe un texte en paragraphes propres. */
    public static function paragraphs(string $text): array
    {
        $parts = preg_split('/\n\s*\n/u', trim(str_replace("\r\n", "\n", $text))) ?: [];
        return array_values(array_filter(array_map('trim', $parts), fn ($p) => $p !== ''));
    }
}
