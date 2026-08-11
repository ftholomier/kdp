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

    /** Encadrés éditoriaux reconnus dans le texte des sections. */
    public const CALLOUTS = [
        'retenir'   => 'À retenir',
        'chiffre'   => 'Chiffre clé',
        'conseil'   => 'Conseil',
        'exemple'   => 'Exemple',
        'faq'       => 'Question fréquente',
        'attention' => 'Attention',
    ];

    /**
     * Découpe un contenu de section en blocs typés :
     *   {t:'p', text}  ·  {t:'list', items[]}  ·  {t:'call', kind, text}
     * Les encadrés utilisent la syntaxe :
     *   :::conseil
     *   Texte de l'encadré…
     *   :::
     */
    public static function blocks(string $text): array
    {
        $lines = explode("\n", trim(str_replace("\r\n", "\n", $text)));
        $blocks = [];
        $buffer = [];
        $callout = null;      // ['kind' => ..., 'lines' => []]

        $flush = function () use (&$buffer, &$blocks): void {
            $chunk = trim(implode("\n", $buffer));
            $buffer = [];
            if ($chunk === '') {
                return;
            }
            foreach (self::paragraphs($chunk) as $paragraph) {
                $rows = array_map('trim', explode("\n", $paragraph));
                $isList = count(array_filter($rows, fn ($r) => preg_match('/^[–\-•]\s+/u', $r))) >= max(1, (int) floor(count($rows) * 0.6));
                if ($isList && count($rows) > 1) {
                    $items = array_values(array_filter(array_map(
                        fn ($r) => trim(preg_replace('/^[–\-•]\s+/u', '', $r) ?? ''),
                        $rows
                    ), fn ($r) => $r !== ''));
                    $blocks[] = ['t' => 'list', 'items' => $items];
                } else {
                    $blocks[] = ['t' => 'p', 'text' => str_replace("\n", ' ', $paragraph)];
                }
            }
        };

        foreach ($lines as $line) {
            $trimmed = trim($line);
            // Sous-titre markdown résiduel (## à ####) : bloc typé, jamais du texte brut
            if ($callout === null && preg_match('/^#{2,4}\s+(.+)$/u', $trimmed, $m)) {
                $flush();
                $blocks[] = ['t' => 'h', 'text' => trim($m[1], " \t#")];
                continue;
            }
            if ($callout === null && preg_match('/^:::\s*([a-zé]+)\s*$/u', $trimmed, $m)
                && isset(self::CALLOUTS[$m[1]])) {
                $flush();
                $callout = ['kind' => $m[1], 'lines' => []];
                continue;
            }
            if ($callout !== null && preg_match('/^:::\s*$/', $trimmed)) {
                $content = trim(implode("\n", $callout['lines']));
                if ($content !== '') {
                    $blocks[] = ['t' => 'call', 'kind' => $callout['kind'], 'text' => $content];
                }
                $callout = null;
                continue;
            }
            if ($callout !== null) {
                $callout['lines'][] = $line;
            } else {
                $buffer[] = $line;
            }
        }
        if ($callout !== null) {
            // encadré jamais refermé : on le récupère quand même
            $content = trim(implode("\n", $callout['lines']));
            if ($content !== '') {
                $blocks[] = ['t' => 'call', 'kind' => $callout['kind'], 'text' => $content];
            }
        }
        $flush();
        return $blocks;
    }
}
