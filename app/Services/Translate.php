<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Util;

/**
 * Traduction d'un livre déjà rédigé : crée le projet « version étrangère »
 * (structure identique, titres et textes de couverture traduits en un seul
 * appel IA), puis l'étape 05 TRADUIT section par section avec les mêmes
 * points de contrôle que la rédaction — cron et reprise sur erreur compris.
 */
final class Translate
{
    public const LANGS = [
        'en' => 'anglais',
        'de' => 'allemand',
        'es' => 'espagnol',
        'it' => 'italien',
        'pt' => 'portugais',
        'nl' => 'néerlandais',
    ];

    public static function createProject(int $userId, array $source, string $langCode): array
    {
        $lang = self::LANGS[$langCode] ?? null;
        if ($lang === null) {
            throw new \RuntimeException('Langue non gérée. Choix : ' . implode(', ', array_keys(self::LANGS)) . '.');
        }
        $sourceId = (int) $source['id'];
        $written = Db::one(
            "SELECT COUNT(*) AS n FROM sections s JOIN chapters c ON c.id = s.chapter_id
             WHERE c.project_id = ? AND s.status = 'done'",
            [$sourceId]
        );
        if ((int) $written['n'] === 0) {
            throw new \RuntimeException('Le livre source doit être rédigé avant d\'être traduit (étape 05).');
        }

        // Titres de chapitres/sections + textes de couverture : UN SEUL appel
        $chapters = Db::all('SELECT id, num, role, title, target_words, status FROM chapters WHERE project_id = ? ORDER BY num', [$sourceId]);
        $sectionsByChapter = [];
        $titleLines = [];
        foreach ($chapters as $chapter) {
            $titleLines[] = 'C' . $chapter['num'] . ' : ' . $chapter['title'];
            $sectionsByChapter[(int) $chapter['num']] = Db::all('SELECT num, title, status FROM sections WHERE chapter_id = ? ORDER BY num', [(int) $chapter['id']]);
            foreach ($sectionsByChapter[(int) $chapter['num']] as $section) {
                $titleLines[] = 'C' . $chapter['num'] . 'S' . $section['num'] . ' : ' . $section['title'];
            }
        }
        $cover = Db::one('SELECT * FROM covers WHERE project_id = ?', [$sourceId]);
        $texts = $cover ? (json_decode((string) $cover['texts'], true) ?: []) : [];
        foreach (['title', 'subtitle', 'tagline', 'back_text', 'bio'] as $key) {
            if (!empty($texts[$key])) {
                $titleLines[] = 'COVER_' . strtoupper($key) . ' : ' . $texts[$key];
            }
        }
        // ÉLÉMENTS de l'éditeur visuel : la conception exacte (positions, polices,
        // graisses, couleurs, motifs) est conservée telle quelle — seuls les
        // TEXTES des éléments partent en traduction, ligne par ligne.
        $coverEls = $cover ? (json_decode((string) ($cover['layout_json'] ?? ''), true) ?: []) : [];
        foreach ($coverEls as $i => $el) {
            if (($el['type'] ?? '') === 'text' && trim((string) ($el['text'] ?? '')) !== '') {
                $titleLines[] = 'COVEREL_' . $i . ' : ' . str_replace("\n", ' ', (string) $el['text']);
            }
        }

        $data = Gemini::json(
            "TRADUIS en {$lang} chacune de ces lignes (titres de chapitres/sections et textes de couverture d'un livre pratique). "
            . "Traductions naturelles et vendeuses, pas littérales.\n\n" . implode("\n", $titleLines) . "\n\n"
            . 'Réponds UNIQUEMENT en JSON : {"lines":{"C2":"…","C2S1":"…","COVER_TITLE":"…", …}} — mêmes clés, valeurs traduites.',
            ['model' => 'fast', 'temperature' => 0.5, 'timeout' => 60, 'retries' => 1,
             'system' => "Tu es traducteur éditorial vers le {$lang}."]
        );
        $lines = (array) ($data['lines'] ?? []);
        $tr = fn (string $key, string $fallback): string => mb_substr(trim((string) ($lines[$key] ?? '')), 0, 250) ?: $fallback;

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $newId = Db::insert(
                'INSERT INTO projects (user_id, title, step, mode, idea, brief, pages, pages_per_chapter, final_pages, photos, photos_per, photo_style, tone, trim_format, interior_theme, layout_options, writing_status, translate_from, translate_lang, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $userId,
                    $tr('COVER_TITLE', (string) $source['title']) . ' — ' . strtoupper($langCode),
                    5, // direction l'étape 05 : la traduction s'écrit comme une rédaction
                    (string) $source['mode'],
                    (string) $source['idea'],
                    // La version étrangère hérite des mêmes consignes d'auteur.
                    (string) ($source['brief'] ?? ''),
                    (int) $source['pages'],
                    $source['pages_per_chapter'] !== null ? (int) $source['pages_per_chapter'] : null,
                    $source['final_pages'] !== null ? (int) $source['final_pages'] : null,
                    (int) $source['photos'], (int) $source['photos_per'], (string) $source['photo_style'],
                    (string) $source['tone'], (string) $source['trim_format'],
                    (string) ($source['interior_theme'] ?? 'editorial'),
                    $source['layout_options'] ?? null,
                    'paused',
                    $sourceId,
                    $lang,
                    Db::now(), Db::now(),
                ]
            );

            foreach ($chapters as $chapter) {
                $num = (int) $chapter['num'];
                $chapterId = Db::insert(
                    "INSERT INTO chapters (project_id, num, role, title, target_words, status) VALUES (?,?,?,?,?,'wait')",
                    [$newId, $num, (string) $chapter['role'], $tr('C' . $num, (string) $chapter['title']), (int) $chapter['target_words']]
                );
                foreach ($sectionsByChapter[$num] as $section) {
                    Db::run(
                        "INSERT INTO sections (chapter_id, num, title, status) VALUES (?,?,?,'wait')",
                        [$chapterId, (int) $section['num'], $tr('C' . $num . 'S' . $section['num'], (string) $section['title'])]
                    );
                }
            }

            // Visuels : mêmes emplacements, mêmes fichiers (les images n'ont pas de langue)
            foreach (Db::all('SELECT * FROM images WHERE project_id = ?', [$sourceId]) as $image) {
                Db::run(
                    'INSERT INTO images (project_id, chapter_num, slot, caption, spec, filename, width, height, created_at) VALUES (?,?,?,?,?,?,?,?,?)',
                    [$newId, (int) $image['chapter_num'], (int) $image['slot'], (string) $image['caption'],
                     (string) $image['spec'], $image['filename'], $image['width'], $image['height'], Db::now()]
                );
            }

            // Couverture : conception STRICTEMENT identique (gabarit, palette,
            // éléments de l'éditeur, illustration IA), textes traduits.
            if ($cover) {
                foreach (['title', 'subtitle', 'tagline', 'back_text', 'bio'] as $key) {
                    if (!empty($texts[$key])) {
                        $texts[$key] = $lines['COVER_' . strtoupper($key)] ?? $texts[$key];
                    }
                }
                // Éléments : on ne touche QUE la propriété « text » ; position,
                // taille, police, graisse, couleur, motifs restent à l'identique.
                foreach ($coverEls as $i => &$el) {
                    if (($el['type'] ?? '') !== 'text') {
                        continue;
                    }
                    $original = (string) ($el['text'] ?? '');
                    $translated = trim((string) ($lines['COVEREL_' . $i] ?? ''));
                    if ($translated === '' || $original === '') {
                        continue;
                    }
                    // Un texte tout en capitales le reste après traduction
                    if ($original !== '' && mb_strtoupper($original) === $original && preg_match('/\p{L}/u', $original)) {
                        $translated = mb_strtoupper($translated);
                    }
                    $el['text'] = $translated;
                }
                unset($el);

                Db::run(
                    'INSERT INTO covers (project_id, template, palette, texts, layout_json, updated_at) VALUES (?,?,?,?,?,?)',
                    [$newId, (string) $cover['template'], (string) $cover['palette'],
                     json_encode($texts, JSON_UNESCAPED_UNICODE),
                     $coverEls ? json_encode($coverEls, JSON_UNESCAPED_UNICODE) : null,
                     Db::now()]
                );

                // Illustration IA et image de référence : recopiées sur le
                // nouveau projet (sans elles, la couverture perdrait son visuel).
                foreach ([
                    [CoverStudio::illusPath($sourceId), CoverStudio::illusPath($newId)],
                    [CoverStudio::refPath($sourceId), CoverStudio::refPath($newId)],
                ] as [$from, $to]) {
                    if (is_file($from)) {
                        @copy($from, $to);
                    }
                }
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        Util::journal($newId, 'ok', 'Projet de TRADUCTION (' . $lang . ') créé depuis « ' . $source['title'] . ' » — lancez l\'étape 05 : chaque section sera traduite.');
        return ['id' => $newId];
    }
}
