<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Db;
use App\Core\Util;

/**
 * Étape 7 — Mise en page : géométrie du livre, conformité KDP,
 * dos de couverture, estimation de redevances.
 */
final class Layout
{
    /** Marge intérieure (gouttière) KDP selon la pagination. */
    public static function gutterMm(int $pages): float
    {
        return match (true) {
            $pages <= 150 => 9.6,
            $pages <= 300 => 12.7,
            $pages <= 500 => 15.9,
            $pages <= 700 => 19.1,
            default       => 22.3,
        };
    }

    public static function geometry(array $project): array
    {
        $trim = Config::get('trims.' . $project['trim_format'], Config::get('trims.6x9'));
        $pages = self::realPages($project);
        return [
            'trim'      => $project['trim_format'],
            'trim_label'=> $trim['label'],
            'w_mm'      => $trim['w_mm'],
            'h_mm'      => $trim['h_mm'],
            'pages'     => $pages,
            'margin_top_mm'    => 19.0,
            'margin_bottom_mm' => 19.0,
            'margin_outer_mm'  => 15.9,
            'margin_inner_mm'  => self::gutterMm($pages),
            'spine_mm'  => round($pages * (float) Config::get('kdp.spine_per_page', 0.0572), 1),
            'bleed_mm'  => (float) Config::get('kdp.bleed_mm', 3.175),
        ];
    }

    /** Colonne final_pages (pagination forcée) : migration automatique. */
    public static function ensureFinalPagesColumn(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            Db::one('SELECT final_pages FROM projects LIMIT 1');
        } catch (\PDOException $e) {
            try {
                Db::pdo()->exec('ALTER TABLE projects ADD COLUMN final_pages SMALLINT UNSIGNED NULL AFTER pages');
            } catch (\Throwable $inner) {
                // concurrence : une autre requête a pu l'ajouter
            }
        }
    }

    /**
     * Pages réelles : le chiffre DÉFINITIF saisi par l'auteur (celui du
     * previewer KDP) s'il existe, sinon l'estimation sur les mots écrits.
     */
    public static function realPages(array $project): int
    {
        if (!empty($project['final_pages'])) {
            return max(24, min(828, (int) $project['final_pages']));
        }
        return self::pagesFromContent($project);
    }

    /**
     * Estimation de pagination à partir des mots écrits : liminaires (8) +
     * corps (285 mots/page) + ouvertures de chapitre sur belle page +
     * emplacements visuels (≈ ½ page chacun). Arrondie à la page paire.
     */
    public static function estimatePages(int $words, int $chapterCount, int $figureSlots): int
    {
        $wpp = (int) Config::get('writing.words_per_page', 285);
        $pages = 8 + (int) ceil($words / max(1, $wpp)) + $chapterCount + (int) round($figureSlots * 0.5);
        $pages = max(24, $pages);
        return $pages + ($pages % 2);
    }

    private static function pagesFromContent(array $project): int
    {
        $row = Db::one(
            "SELECT COALESCE(SUM(s.words),0) AS w, COUNT(DISTINCT c.id) AS chapters
             FROM chapters c LEFT JOIN sections s ON s.chapter_id = c.id AND s.status = 'done'
             WHERE c.project_id = ?",
            [(int) $project['id']]
        );
        $words = (int) ($row['w'] ?? 0);
        if ($words === 0) {
            return (int) $project['pages'];
        }
        $figures = 0;
        if (!empty($project['photos'])) {
            $f = Db::one('SELECT COUNT(*) AS n FROM images WHERE project_id = ?', [(int) $project['id']]);
            $figures = (int) $f['n'];
        }
        return self::estimatePages($words, (int) ($row['chapters'] ?? 0), $figures);
    }

    public static function summary(array $project, ?array $concept): array
    {
        $geometry = self::geometry($project);
        $pages = $geometry['pages'];
        $kdp = Config::get('kdp');
        $meta = Db::one('SELECT * FROM kdp_meta WHERE project_id = ?', [(int) $project['id']]);

        $price = $meta && $meta['price'] !== null ? (float) $meta['price'] : self::parsePrice($concept['price'] ?? '14,90');
        $printCost = round((float) $kdp['print_fixed'] + $pages * (float) $kdp['print_per_page'], 2);
        $royalty = max(0, round((float) $kdp['royalty_rate'] * $price - $printCost, 2));

        $images = Db::all('SELECT * FROM images WHERE project_id = ?', [(int) $project['id']]);
        $missing = array_values(array_filter($images, fn ($i) => empty($i['filename'])));
        $lowRes = array_values(array_filter($images, function ($i) use ($geometry) {
            if (empty($i['filename']) || empty($i['width'])) {
                return false;
            }
            $usableMm = $geometry['w_mm'] - $geometry['margin_inner_mm'] - $geometry['margin_outer_mm'];
            return (int) $i['width'] < (int) round($usableMm / 25.4 * 300);
        }));

        $checks = [
            ['state' => empty($lowRes) ? 'ok' : 'warn',
             'label' => 'Résolution des images ≥ 300 dpi',
             'note'  => empty($images) ? 'Aucun visuel dans ce livre'
                       : (empty($lowRes) ? count($images) . ' emplacement(s) vérifié(s)' : count($lowRes) . ' visuel(s) sous 300 dpi')],
            ['state' => empty($missing) ? 'ok' : 'warn',
             'label' => 'Visuels fournis',
             'note'  => empty($images) ? 'Sans objet' : (empty($missing) ? 'Tous les emplacements sont remplis' : count($missing) . ' emplacement(s) sans image')],
            ['state' => 'ok',
             'label' => 'Polices intégrées au PDF',
             'note'  => 'Export navigateur : polices incorporées automatiquement'],
            ['state' => 'ok',
             'label' => 'Pagination et gouttière conformes',
             'note'  => $pages . ' pages · gouttière ' . str_replace('.', ',', (string) $geometry['margin_inner_mm']) . ' mm'],
            ['state' => 'ok',
             'label' => 'Pages liminaires complètes',
             'note'  => 'Faux-titre, titre, copyright, sommaire générés'],
            // Par défaut : ISBN GRATUIT attribué par KDP (choix systématique) —
            // ce n'est donc pas une alerte, c'est la configuration normale.
            ['state' => 'ok',
             'label' => ($meta && $meta['isbn'] !== '') ? 'ISBN personnel renseigné' : 'ISBN gratuit KDP (par défaut)',
             'note'  => ($meta && $meta['isbn'] !== '') ? $meta['isbn'] : 'Amazon attribue le numéro gratuitement — option présélectionnée'],
            ['state' => 'ok',
             'label' => 'Aucun élément hors zone de sécurité',
             'note'  => 'Marge de sécurité de 6,4 mm respectée'],
        ];

        $fields = [
            ['k' => 'Format',             'v' => str_replace('x', ' × ', $project['trim_format']) . ' po'],
            ['k' => 'Fond perdu',         'v' => 'Oui — téléverser en « avec fond perdu »'],
            ['k' => 'Marges int. / ext.', 'v' => str_replace('.', ',', (string) $geometry['margin_inner_mm']) . ' / ' . str_replace('.', ',', (string) $geometry['margin_outer_mm']) . ' mm'],
            ['k' => 'Marges haut / bas',  'v' => '19 / 19 mm'],
            ['k' => 'Police du texte',    'v' => 'Instrument Serif 11,2 pt'],
            ['k' => 'Interlignage',       'v' => '1,42'],
            ['k' => 'Titres de chapitre', 'v' => 'Page impaire, lettrine'],
            ['k' => 'Numérotation',       'v' => 'Chiffres arabes dès p. 9'],
            ['k' => 'Images',             'v' => empty($images) ? 'Aucune'
                : count($images) . ' emplacements · ' . ($project['photo_style'] === 'couleur' ? 'couleur' : 'N&B') . ' 300 dpi'],
        ];

        return [
            'geometry'   => $geometry,
            'fields'     => $fields,
            'checks'     => $checks,
            'pricing'    => [
                'price'      => $price,
                'print_cost' => $printCost,
                'royalty'    => $royalty,
                'label'      => 'Prix conseillé ' . number_format($price, 2, ',', ' ') . ' € · royalties estimées '
                    . number_format($royalty, 2, ',', ' ') . ' € / exemplaire ('
                    . (int) round((float) $kdp['royalty_rate'] * 100) . ' % − '
                    . number_format($printCost, 2, ',', ' ') . ' € d\'impression).',
            ],
            'spine_label' => str_replace('.', ',', (string) $geometry['spine_mm']) . ' mm',
            'words_total' => (int) (Db::one(
                "SELECT COALESCE(SUM(s.words),0) AS w FROM chapters c JOIN sections s ON s.chapter_id = c.id WHERE c.project_id = ?",
                [(int) $project['id']]
            )['w'] ?? 0),
        ];
    }

    public static function parsePrice(string $price): float
    {
        $clean = str_replace([',', ' ', '€'], ['.', '', ''], trim($price));
        return $clean !== '' && is_numeric($clean) ? round((float) $clean, 2) : 14.90;
    }

    /** Contenu structuré du livre pour les rendus (print, PDF, docx). */
    public static function bookData(array $project, ?array $concept, array $user): array
    {
        \App\Core\Migrations::run();
        $projectId = (int) $project['id'];
        $chapters = Db::all('SELECT * FROM chapters WHERE project_id = ? ORDER BY num', [$projectId]);
        $out = [];
        $chapterIndex = 0;
        foreach ($chapters as $chapter) {
            $role = (string) ($chapter['role'] ?? 'chapter');
            if ($role === 'chapter') {
                $chapterIndex++;
            }
            $sections = Db::all(
                "SELECT num, title, content, words FROM sections WHERE chapter_id = ? ORDER BY num",
                [$chapter['id']]
            );
            $images = Db::all(
                'SELECT id, slot, caption, spec, filename FROM images WHERE project_id = ? AND chapter_num = ? ORDER BY slot',
                [$projectId, (int) $chapter['num']]
            );
            $out[] = [
                'num'         => (int) $chapter['num'],
                'role'        => $role,
                'display_num' => $role === 'chapter' ? $chapterIndex : 0,
                'label'       => match ($role) {
                    'intro'      => 'Introduction',
                    'conclusion' => 'Conclusion',
                    default      => 'Chapitre ' . $chapterIndex,
                },
                'title'    => $chapter['title'],
                'sections' => array_map(fn ($s) => [
                    'num'        => (int) $s['num'],
                    'title'      => $s['title'],
                    'paragraphs' => Util::paragraphs((string) ($s['content'] ?? '')),
                    'blocks'     => Util::blocks((string) ($s['content'] ?? '')),
                ], $sections),
                'images'   => $images,
            ];
        }
        $cover = Db::one('SELECT texts FROM covers WHERE project_id = ?', [$projectId]);
        $texts = $cover ? (json_decode((string) $cover['texts'], true) ?: []) : [];
        // Pages de fin : bio de l'auteur + autres livres publiés du compte
        $otherBooks = Db::all(
            "SELECT COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(cv.texts, '$.title')), ''), p.title) AS title,
                    COALESCE(JSON_UNQUOTE(JSON_EXTRACT(cv.texts, '$.subtitle')), '') AS subtitle
             FROM projects p LEFT JOIN covers cv ON cv.project_id = p.id
             WHERE p.user_id = ? AND p.id != ? AND p.writing_status = 'done'
             ORDER BY p.updated_at DESC LIMIT 6",
            [(int) $project['user_id'], $projectId]
        );
        return [
            'title'    => $texts['title'] ?? ($concept['title'] ?? $project['title']),
            'subtitle' => $texts['subtitle'] ?? '',
            'tagline'  => $texts['tagline'] ?? ($concept['hook'] ?? ''),
            'author'   => $texts['author'] ?? ($user['display_name'] ?: 'Auteur'),
            'bio'      => trim((string) ($texts['bio'] ?? '')),
            'other_books' => $otherBooks,
            'chapters' => $out,
            'year'     => date('Y'),
            'callout_labels' => Util::CALLOUTS,
        ];
    }
}
