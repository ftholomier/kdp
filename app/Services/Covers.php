<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Db;

/**
 * Étape 4 — Couverture : 1ère et 4ème de couverture en flat design.
 *
 * Les gabarits vivent dans templates/covers/<slug>/ :
 *   - meta.json  : {"name":"...", "palette":{"c1":"#…","c2":"#…","c3":"#…","c4":"#…"}}
 *   - front.svg  : 1ère de couverture, jetons {{TITLE}} {{SUBTITLE}} {{TAGLINE}} {{AUTHOR}} {{C1}}..{{C4}}
 *   - back.svg   : 4ème de couverture, jetons {{BACK_TEXT}} {{BIO}} {{TAGLINE}} {{AUTHOR}} {{TITLE}} {{C1}}..{{C4}}
 *
 * Déposez vos propres modèles (flat design) dans un nouveau dossier :
 * ils apparaissent automatiquement dans l'interface et sont respectés tels quels.
 */
final class Covers
{
    public static function templates(): array
    {
        $dir = (string) Config::get('paths.cover_templates');
        $out = [];
        foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $path) {
            $slug = basename($path);
            if (!is_file($path . '/front.svg') || !is_file($path . '/back.svg')) {
                continue;
            }
            $meta = json_decode((string) @file_get_contents($path . '/meta.json'), true) ?: [];
            $out[] = [
                'slug'    => $slug,
                'name'    => $meta['name'] ?? ucfirst($slug),
                'palette' => $meta['palette'] ?? ['c1' => '#1B2A4A', 'c2' => '#C4571F', 'c3' => '#F4EFE4', 'c4' => '#1A1A17'],
            ];
        }
        usort($out, fn ($a, $b) => strcmp($a['slug'], $b['slug']));
        return $out;
    }

    /** Colonne layout_json (éléments de l'éditeur) : migration automatique. */
    private static function ensureLayoutColumn(): void
    {
        foreach (['layout_json', 'layout_back_json'] as $column) {
            try {
                Db::one("SELECT {$column} FROM covers LIMIT 1");
            } catch (\PDOException $e) {
                try {
                    Db::pdo()->exec("ALTER TABLE covers ADD COLUMN {$column} MEDIUMTEXT NULL");
                } catch (\Throwable $inner) {
                    // concurrence : une autre requête a pu l'ajouter
                }
            }
        }
    }

    /**
     * Enregistre les éléments de l'éditeur pour une FACE ('front' | 'back')
     * + synchronise les textes canon (1ère de couverture uniquement).
     */
    public static function saveLayout(int $projectId, array $els, string $face = 'front'): array
    {
        self::ensureLayoutColumn();
        $clean = CoverStudio::sanitizeElements($els);
        $column = $face === 'back' ? 'layout_back_json' : 'layout_json';
        Db::run(
            "UPDATE covers SET {$column} = ?, updated_at = ? WHERE project_id = ?",
            [json_encode($clean, JSON_UNESCAPED_UNICODE), Db::now(), $projectId]
        );
        if ($face === 'back') {
            return $clean;
        }
        // Les textes des éléments title/tagline/subtitle restent la référence
        $row = Db::one('SELECT texts FROM covers WHERE project_id = ?', [$projectId]);
        $texts = json_decode((string) ($row['texts'] ?? ''), true) ?: [];
        foreach ($clean as $el) {
            if (($el['type'] ?? '') === 'text' && in_array($el['id'] ?? '', ['title', 'tagline', 'subtitle'], true)) {
                $texts[$el['id']] = $el['text'];
            }
        }
        Db::run('UPDATE covers SET texts = ? WHERE project_id = ?', [json_encode($texts, JSON_UNESCAPED_UNICODE), $projectId]);
        return $clean;
    }

    /** Charge (ou initialise) la couverture d'un projet. */
    public static function get(array $project, ?array $concept, array $user): array
    {
        self::ensureLayoutColumn();
        $projectId = (int) $project['id'];
        $row = Db::one('SELECT * FROM covers WHERE project_id = ?', [$projectId]);
        if (!$row) {
            $templates = self::templates();
            $template = $templates[0]['slug'] ?? 'editorial';
            $palette = $templates[0]['palette'] ?? ['c1' => '#1B2A4A', 'c2' => '#C4571F', 'c3' => '#F4EFE4', 'c4' => '#1A1A17'];
            $texts = [
                'title'     => $concept['title'] ?? $project['title'],
                'subtitle'  => $concept['description'] ? self::firstSentence((string) $concept['description']) : '',
                'tagline'   => $concept['hook'] ?? '',
                'author'    => $user['display_name'] ?: 'Auteur',
                'back_text' => '',
                'bio'       => '',
            ];
            Db::run(
                'INSERT INTO covers (project_id, template, palette, texts, updated_at) VALUES (?,?,?,?,?)',
                [$projectId, $template, json_encode($palette, JSON_UNESCAPED_UNICODE), json_encode($texts, JSON_UNESCAPED_UNICODE), Db::now()]
            );
            $row = Db::one('SELECT * FROM covers WHERE project_id = ?', [$projectId]);
        }
        $row['palette'] = json_decode((string) $row['palette'], true) ?: [];
        $row['texts'] = json_decode((string) $row['texts'], true) ?: [];
        $row['els'] = json_decode((string) ($row['layout_json'] ?? ''), true) ?: null;
        $row['els_back'] = json_decode((string) ($row['layout_back_json'] ?? ''), true) ?: null;
        unset($row['layout_json'], $row['layout_back_json']);
        return $row;
    }

    /**
     * Éléments effectifs de la 1ère de couverture : ceux édités, sinon la
     * mise en page courante convertie en éléments.
     */
    public static function frontElements(array $cover, bool $hasIllustration): array
    {
        if (is_array($cover['els']) && $cover['els']) {
            return $cover['els'];
        }
        $palette = $cover['palette'];
        return CoverStudio::layoutElements(
            (string) ($palette['layout'] ?? 'affiche'),
            $palette,
            (string) ($palette['motif'] ?? CoverStudio::motifFor((string) ($cover['texts']['title'] ?? ''))),
            $cover['texts'],
            $hasIllustration
        );
    }

    /**
     * Éléments effectifs de la 4ème de couverture : ceux édités dans le studio,
     * sinon la 4ème composée automatiquement à partir des textes.
     */
    public static function backElements(array $cover): array
    {
        if (!empty($cover['els_back']) && is_array($cover['els_back'])) {
            return $cover['els_back'];
        }
        return CoverStudio::backElements($cover['palette'], $cover['texts']);
    }

    public static function save(int $projectId, string $template, array $palette, array $texts): void
    {
        $clean = [];
        foreach (['c1', 'c2', 'c3', 'c4'] as $key) {
            $value = (string) ($palette[$key] ?? '');
            $clean[$key] = preg_match('/^#[0-9a-fA-F]{3,8}$/', $value) ? $value : '#1B2A4A';
        }
        // Studio : mise en page et motif choisis vivent dans le même JSON
        if (in_array($palette['layout'] ?? '', CoverStudio::LAYOUTS, true)) {
            $clean['layout'] = $palette['layout'];
        }
        if (in_array($palette['motif'] ?? '', CoverStudio::MOTIFS, true)) {
            $clean['motif'] = $palette['motif'];
        }
        $textsClean = [];
        foreach (['title', 'subtitle', 'tagline', 'author', 'back_text', 'bio', 'illus_prompt'] as $key) {
            $textsClean[$key] = mb_substr(trim((string) ($texts[$key] ?? '')), 0, $key === 'back_text' ? 1500 : ($key === 'illus_prompt' ? 800 : 300));
        }
        Db::run(
            'UPDATE covers SET template = ?, palette = ?, texts = ?, updated_at = ? WHERE project_id = ?',
            [
                preg_replace('/[^a-z0-9_-]/i', '', $template) ?: 'editorial',
                json_encode($clean, JSON_UNESCAPED_UNICODE),
                json_encode($textsClean, JSON_UNESCAPED_UNICODE),
                Db::now(),
                $projectId,
            ]
        );

        // Répercute les textes canon dans les éléments édités (titre, accroche…)
        self::ensureLayoutColumn();
        $row = Db::one('SELECT layout_json, layout_back_json FROM covers WHERE project_id = ?', [$projectId]);
        foreach (['layout_json', 'layout_back_json'] as $column) {
            $els = json_decode((string) ($row[$column] ?? ''), true);
            if (!is_array($els) || !$els) {
                continue;
            }
            $changed = false;
            foreach ($els as &$el) {
                $id = $el['id'] ?? '';
                if (($el['type'] ?? '') === 'text' && isset($textsClean[$id]) && $textsClean[$id] !== ($el['text'] ?? null)) {
                    $el['text'] = $textsClean[$id];
                    $changed = true;
                }
            }
            unset($el);
            if ($changed) {
                Db::run("UPDATE covers SET {$column} = ? WHERE project_id = ?", [json_encode($els, JSON_UNESCAPED_UNICODE), $projectId]);
            }
        }
    }

    /** Texte de 4ème de couverture + accroche + bio générés par Gemini. */
    public static function generateBack(array $project, array $concept, array $user): array
    {
        // Les textes de couverture suivent la LANGUE DU LIVRE : une 4e de
        // couverture française sur un livre anglais ne se vend pas.
        $langName = Lang::promptName(Lang::codeOf($project));

        $prompt = Brief::block($project, 'aux textes de couverture')
            . "Tu es copywriter éditorial. Rédige les textes de couverture d'un livre pratique rédigé "
            . "en {$langName}, destiné à Amazon KDP.\n"
            . "Titre : « {$concept['title']} »\nAccroche existante : « {$concept['hook']} »\n"
            . "Promesse : {$concept['description']}\nTon : {$project['tone']}.\n\n"
            . "Réponds UNIQUEMENT avec un objet JSON valide :\n"
            . '{"subtitle":"...","tagline":"...","back_text":"...","bio":"..."}' . "\n"
            . "Contraintes :\n"
            . "- tous les textes sont rédigés EN {$langName}, la langue du livre ;\n"
            . "- \"subtitle\" : SOUS-TITRE de couverture, qui précise la promesse et porte les mots-clés "
            . "que tapent les acheteurs (max 120 caractères, sans répéter le titre) ;\n"
            . "- \"tagline\" : accroche de 1ère de couverture, une phrase percutante (max 80 caractères) ;\n"
            . "- \"back_text\" : 4ème de couverture, 3 courts paragraphes séparés par \\n\\n : le problème vécu "
            . "par le lecteur, la promesse du livre, ce qu'il contient concrètement (3 puces « • » possibles). "
            . "120 à 170 mots, vendeur mais crédible ;\n"
            . "- \"bio\" : notice auteur de 2 phrases à la 3ème personne pour « " . ($user['display_name'] ?: 'l\'auteur') . " »."
            . Brief::reminder($project);

        $data = Gemini::json($prompt, [
            'model'       => 'fast',
            'temperature' => 0.9,
            'search'      => false,
            'system'      => "Tu écris des textes de couverture qui vendent, dans un {$langName} impeccable."
                . Brief::systemLine($project),
        ]);

        return [
            'subtitle'  => mb_substr(trim((string) ($data['subtitle'] ?? '')), 0, 300),
            'tagline'   => mb_substr(trim((string) ($data['tagline'] ?? '')), 0, 300),
            'back_text' => mb_substr(trim((string) ($data['back_text'] ?? '')), 0, 1500),
            'bio'       => mb_substr(trim((string) ($data['bio'] ?? '')), 0, 300),
        ];
    }

    /** Rend le SVG d'une face avec les jetons remplacés. */
    public static function render(array $cover, string $face): string
    {
        $face = $face === 'back' ? 'back' : 'front';
        $dir = (string) Config::get('paths.cover_templates');
        $file = $dir . '/' . $cover['template'] . '/' . $face . '.svg';
        if (!is_file($file)) {
            $fallback = self::templates()[0]['slug'] ?? null;
            $file = $fallback ? $dir . '/' . $fallback . '/' . $face . '.svg' : null;
        }
        if (!$file || !is_file($file)) {
            throw new \RuntimeException('Gabarit de couverture introuvable.');
        }
        $svg = (string) file_get_contents($file);

        $texts = $cover['texts'];
        $palette = $cover['palette'];
        $esc = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $backHtml = '';
        foreach (preg_split('/\n\s*\n/', (string) ($texts['back_text'] ?? '')) ?: [] as $paragraph) {
            $backHtml .= '<p>' . nl2br($esc(trim($paragraph))) . '</p>';
        }

        $replacements = [
            '{{TITLE}}'     => $esc((string) ($texts['title'] ?? '')),
            '{{SUBTITLE}}'  => $esc((string) ($texts['subtitle'] ?? '')),
            '{{TAGLINE}}'   => $esc((string) ($texts['tagline'] ?? '')),
            '{{AUTHOR}}'    => $esc((string) ($texts['author'] ?? '')),
            '{{BIO}}'       => $esc((string) ($texts['bio'] ?? '')),
            '{{BACK_TEXT}}' => $backHtml,
            '{{C1}}'        => $esc((string) ($palette['c1'] ?? '#1B2A4A')),
            '{{C2}}'        => $esc((string) ($palette['c2'] ?? '#C4571F')),
            '{{C3}}'        => $esc((string) ($palette['c3'] ?? '#F4EFE4')),
            '{{C4}}'        => $esc((string) ($palette['c4'] ?? '#1A1A17')),
        ];
        return strtr($svg, $replacements);
    }

    private static function firstSentence(string $text): string
    {
        $parts = preg_split('/(?<=[.!?])\s+/u', trim($text), 2) ?: [trim($text)];
        return mb_substr($parts[0], 0, 140);
    }
}
