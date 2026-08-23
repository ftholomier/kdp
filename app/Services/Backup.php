<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Db;
use App\Core\Util;

/**
 * Sauvegarde / restauration COMPLÈTE d'un projet en un fichier JSON :
 * réglages, concepts, chapitres et texte intégral, emplacements visuels avec
 * leurs images (base64), couverture (palette, mise en page, illustration) et
 * métadonnées KDP. Le filet de sécurité de votre travail.
 */
final class Backup
{
    private const FORMAT = 'tirage-project';
    private const VERSION = 1;

    /** Colonnes de projet embarquées dans la sauvegarde (et restaurées). */
    private const PROJECT_COLUMNS = [
        'title', 'step', 'mode', 'idea', 'brief', 'pages', 'pages_per_chapter', 'final_pages',
        'photos', 'photos_per', 'photo_style', 'tone', 'lang', 'trim_format',
        'interior_theme', 'layout_options', 'toc_json', 'writing_status',
    ];

    public static function export(array $project): array
    {
        $projectId = (int) $project['id'];
        $uploads = (string) Config::get('paths.uploads');
        $b64 = function (?string $path): ?string {
            return $path !== null && is_file($path) ? base64_encode((string) file_get_contents($path)) : null;
        };

        $chapters = [];
        foreach (Db::all('SELECT * FROM chapters WHERE project_id = ? ORDER BY num', [$projectId]) as $chapter) {
            $chapter['sections'] = Db::all(
                'SELECT num, title, status, content, words FROM sections WHERE chapter_id = ? ORDER BY num',
                [(int) $chapter['id']]
            );
            unset($chapter['id'], $chapter['project_id']);
            $chapters[] = $chapter;
        }

        $images = [];
        foreach (Db::all('SELECT * FROM images WHERE project_id = ? ORDER BY chapter_num, slot', [$projectId]) as $image) {
            $images[] = [
                'chapter_num' => (int) $image['chapter_num'],
                'slot'        => (int) $image['slot'],
                'caption'     => $image['caption'],
                'spec'        => $image['spec'],
                'width'       => $image['width'],
                'height'      => $image['height'],
                'file_b64'    => !empty($image['filename']) ? $b64($uploads . '/' . $image['filename']) : null,
            ];
        }

        $cover = Db::one('SELECT template, palette, texts, layout_json FROM covers WHERE project_id = ?', [$projectId]);
        $meta = Db::one('SELECT subtitle, author_first, author_last, description_html, keywords, categories, price, isbn FROM kdp_meta WHERE project_id = ?', [$projectId]);

        return [
            'format'      => self::FORMAT,
            'version'     => self::VERSION,
            'exported_at' => date('c'),
            'project'     => array_intersect_key($project, array_flip(self::PROJECT_COLUMNS)),
            'concept_position' => self::selectedConceptPosition($project),
            'concepts'    => array_map(function ($concept) {
                unset($concept['id'], $concept['project_id']);
                return $concept;
            }, Db::all('SELECT * FROM concepts WHERE project_id = ? ORDER BY position', [$projectId])),
            'chapters'    => $chapters,
            'images'      => $images,
            'cover'       => $cover ? [
                'template'    => $cover['template'],
                'palette'     => $cover['palette'],
                'texts'       => $cover['texts'],
                'layout_json' => $cover['layout_json'],
                'illus_b64'   => $b64(CoverStudio::illusPath($projectId)),
                'ref_b64'     => $b64(CoverStudio::refPath($projectId)),
            ] : null,
            'kdp_meta'    => $meta ?: null,
        ];
    }

    /** Restaure une sauvegarde comme NOUVEAU projet de l'utilisateur. */
    public static function import(int $userId, array $data): array
    {
        if (($data['format'] ?? '') !== self::FORMAT || !isset($data['project'])) {
            throw new \RuntimeException('Fichier de sauvegarde invalide (format inattendu).');
        }
        $p = array_intersect_key((array) $data['project'], array_flip(self::PROJECT_COLUMNS));
        $title = mb_substr(trim((string) ($p['title'] ?? 'Projet restauré')), 0, 250) ?: 'Projet restauré';

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $projectId = Db::insert(
                'INSERT INTO projects (user_id, title, step, mode, idea, brief, pages, pages_per_chapter, final_pages, photos, photos_per, photo_style, tone, lang, trim_format, interior_theme, layout_options, toc_json, writing_status, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $userId, $title,
                    max(1, min(7, (int) ($p['step'] ?? 1))),
                    in_array($p['mode'] ?? '', ['describe', 'trends'], true) ? $p['mode'] : 'describe',
                    (string) ($p['idea'] ?? ''),
                    // Vos consignes voyagent avec le projet : une restauration
                    // repart avec la même ligne éditoriale.
                    Brief::clean((string) ($p['brief'] ?? '')),
                    max(24, min(828, (int) ($p['pages'] ?? 120))),
                    empty($p['pages_per_chapter']) ? null : max(4, min(60, (int) $p['pages_per_chapter'])),
                    $p['final_pages'] !== null && $p['final_pages'] !== '' ? (int) $p['final_pages'] : null,
                    (int) !empty($p['photos']),
                    max(1, min(6, (int) ($p['photos_per'] ?? 1))),
                    in_array($p['photo_style'] ?? '', ['nb', 'couleur', 'schemas'], true) ? $p['photo_style'] : 'nb',
                    mb_substr((string) ($p['tone'] ?? ''), 0, 50),
                    isset(Lang::LANGS[(string) ($p['lang'] ?? '')]) ? (string) $p['lang'] : null,
                    Config::get('trims.' . ($p['trim_format'] ?? '')) ? $p['trim_format'] : '6x9',
                    isset(PdfBook::THEMES[$p['interior_theme'] ?? '']) ? $p['interior_theme'] : 'editorial',
                    (string) ($p['layout_options'] ?? ''),
                    (string) ($p['toc_json'] ?? ''),
                    in_array($p['writing_status'] ?? '', ['idle', 'running', 'paused', 'done'], true)
                        ? ($p['writing_status'] === 'running' ? 'paused' : $p['writing_status']) : 'idle',
                    Db::now(), Db::now(),
                ]
            );

            // Concepts (avec re-sélection du concept actif par position)
            $conceptIds = [];
            foreach ((array) ($data['concepts'] ?? []) as $concept) {
                $concept = (array) $concept;
                $columns = array_diff(array_keys($concept), ['id', 'project_id']);
                $values = array_map(fn ($c) => $concept[$c], $columns);
                $conceptIds[(int) ($concept['position'] ?? 0)] = Db::insert(
                    'INSERT INTO concepts (project_id, ' . implode(', ', $columns) . ') VALUES (?' . str_repeat(',?', count($columns)) . ')',
                    array_merge([$projectId], $values)
                );
            }
            $selected = $data['concept_position'] ?? null;
            if ($selected !== null && isset($conceptIds[(int) $selected])) {
                Db::run('UPDATE projects SET concept_id = ? WHERE id = ?', [$conceptIds[(int) $selected], $projectId]);
            }

            // Chapitres + sections (texte intégral)
            foreach ((array) ($data['chapters'] ?? []) as $chapter) {
                $chapter = (array) $chapter;
                $chapterId = Db::insert(
                    "INSERT INTO chapters (project_id, num, role, title, target_words, status) VALUES (?,?,?,?,?,?)",
                    [
                        $projectId,
                        (int) ($chapter['num'] ?? 1),
                        in_array($chapter['role'] ?? '', ['chapter', 'intro', 'conclusion'], true) ? $chapter['role'] : 'chapter',
                        mb_substr((string) ($chapter['title'] ?? 'Chapitre'), 0, 250),
                        (int) ($chapter['target_words'] ?? 2000),
                        in_array($chapter['status'] ?? '', ['wait', 'writing', 'done'], true)
                            ? ($chapter['status'] === 'writing' ? 'wait' : $chapter['status']) : 'wait',
                    ]
                );
                foreach ((array) ($chapter['sections'] ?? []) as $section) {
                    $section = (array) $section;
                    Db::run(
                        'INSERT INTO sections (chapter_id, num, title, status, content, words) VALUES (?,?,?,?,?,?)',
                        [
                            $chapterId,
                            (int) ($section['num'] ?? 1),
                            mb_substr((string) ($section['title'] ?? 'Partie'), 0, 250),
                            in_array($section['status'] ?? '', ['wait', 'writing', 'done'], true)
                                ? ($section['status'] === 'writing' ? 'wait' : $section['status']) : 'wait',
                            $section['content'] ?? null,
                            (int) ($section['words'] ?? 0),
                        ]
                    );
                }
            }

            // Visuels (fichiers restaurés sur le disque)
            $uploads = (string) Config::get('paths.uploads');
            if (!is_dir($uploads)) {
                mkdir($uploads, 0775, true);
            }
            foreach ((array) ($data['images'] ?? []) as $image) {
                $image = (array) $image;
                $filename = null;
                $bin = isset($image['file_b64']) && is_string($image['file_b64'])
                    ? base64_decode($image['file_b64'], true) : false;
                if ($bin !== false && $bin !== null && $bin !== '') {
                    $filename = 'p' . $projectId . '-restore-' . substr(bin2hex(random_bytes(6)), 0, 8) . '.jpg';
                    file_put_contents($uploads . '/' . $filename, $bin);
                }
                Db::run(
                    'INSERT INTO images (project_id, chapter_num, slot, caption, spec, filename, width, height, created_at) VALUES (?,?,?,?,?,?,?,?,?)',
                    [
                        $projectId, (int) ($image['chapter_num'] ?? 1), (int) ($image['slot'] ?? 1),
                        (string) ($image['caption'] ?? ''), (string) ($image['spec'] ?? ''),
                        $filename, $image['width'] ?? null, $image['height'] ?? null, Db::now(),
                    ]
                );
            }

            // Couverture (palette, éléments, illustration IA)
            $cover = (array) ($data['cover'] ?? []);
            if ($cover) {
                Db::run(
                    'INSERT INTO covers (project_id, template, palette, texts, layout_json, updated_at) VALUES (?,?,?,?,?,?)',
                    [
                        $projectId, (string) ($cover['template'] ?? ''), (string) ($cover['palette'] ?? ''),
                        (string) ($cover['texts'] ?? ''), $cover['layout_json'] ?? null, Db::now(),
                    ]
                );
                foreach ([['illus_b64', CoverStudio::illusPath($projectId)], ['ref_b64', CoverStudio::refPath($projectId)]] as [$key, $path]) {
                    $bin = isset($cover[$key]) && is_string($cover[$key]) ? base64_decode($cover[$key], true) : false;
                    if ($bin !== false && $bin !== null && $bin !== '') {
                        file_put_contents($path, $bin);
                    }
                }
            }

            // Métadonnées KDP
            $meta = (array) ($data['kdp_meta'] ?? []);
            if ($meta) {
                Db::run(
                    'INSERT INTO kdp_meta (project_id, subtitle, author_first, author_last, description_html, keywords, categories, price, isbn, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
                    [
                        $projectId,
                        (string) ($meta['subtitle'] ?? ''), (string) ($meta['author_first'] ?? ''),
                        (string) ($meta['author_last'] ?? ''), (string) ($meta['description_html'] ?? ''),
                        (string) ($meta['keywords'] ?? '[]'), (string) ($meta['categories'] ?? '[]'),
                        $meta['price'] ?? null, (string) ($meta['isbn'] ?? ''),
                        Db::now(),
                    ]
                );
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        Util::journal($projectId, 'ok', 'Projet restauré depuis une sauvegarde du ' . mb_substr((string) ($data['exported_at'] ?? '?'), 0, 10));
        return ['id' => $projectId, 'title' => $title];
    }

    private static function selectedConceptPosition(array $project): ?int
    {
        if (empty($project['concept_id'])) {
            return null;
        }
        $row = Db::one('SELECT position FROM concepts WHERE id = ?', [(int) $project['concept_id']]);
        return $row ? (int) $row['position'] : null;
    }
}
