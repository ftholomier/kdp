<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Db;
use App\Core\Util;

/**
 * Étape 3 — Sommaire : génération, édition et validation.
 * Le sommaire vit en brouillon dans projects.toc_json ; la validation
 * crée les chapitres, sections (points de contrôle) et emplacements visuels.
 */
final class Toc
{
    public static function chapterCountFor(int $pages): int
    {
        $w = Config::get('writing');
        return max(
            (int) $w['min_chapters'],
            min((int) $w['max_chapters'], (int) round($pages / (int) $w['pages_per_chapter']))
        );
    }

    public static function generate(array $project, array $concept): array
    {
        $pages = (int) $project['pages'];
        $count = self::chapterCountFor($pages);
        $words = $pages * (int) Config::get('writing.words_per_page', 285);
        $photos = !empty($project['photos']);
        $photosPer = (int) $project['photos_per'];

        $visualSpec = $photos
            ? "- \"visuals\" : exactement {$photosPer} idées de visuels pour le chapitre, chacune "
              . "{\"caption\":\"légende courte\",\"desc\":\"contenu précis du visuel (schéma, photo, tableau…)\"} ;\n"
            : "- pas de champ \"visuals\" ;\n";

        $prompt = "Tu es directeur éditorial. Construis le sommaire d'un livre pratique en français pour Amazon KDP.\n"
            . "Titre : « {$concept['title']} »\n"
            . "Accroche : « {$concept['hook']} »\n"
            . "Promesse : {$concept['description']}\n"
            . "Ton : {$project['tone']}. Longueur cible : {$pages} pages (~" . Util::nf($words) . " mots).\n\n"
            . "Réponds UNIQUEMENT avec un objet JSON valide :\n"
            . '{"chapters":[{"title":"...","parts":["...","...","..."]' . ($photos ? ',"visuals":[{"caption":"...","desc":"..."}]' : '') . '}]}' . "\n"
            . "Contraintes :\n"
            . "- exactement {$count} chapitres, progression logique du problème à la maîtrise ;\n"
            . "- \"title\" : titre de chapitre évocateur, sans numérotation (max 70 caractères) ;\n"
            . "- \"parts\" : exactement 3 sous-parties courtes (3 à 6 mots chacune) ;\n"
            . $visualSpec
            . "- aucun texte hors du JSON.";

        $data = Gemini::json($prompt, [
            'model'       => 'fast',
            'temperature' => (float) Config::get('gemini.temperature_ideas', 0.9),
            'search'      => false,
            'system'      => "Tu construis des sommaires de livres pratiques impeccables. Réponse en français.",
        ]);

        $chapters = $data['chapters'] ?? null;
        if (!is_array($chapters) || count($chapters) < 3) {
            throw new \RuntimeException('Le sommaire généré est incomplet, relancez.');
        }

        $toc = [];
        foreach (array_slice(array_values($chapters), 0, $count) as $chapter) {
            $parts = array_slice(array_values((array) ($chapter['parts'] ?? [])), 0, 3);
            $entry = [
                'title' => mb_substr(trim((string) ($chapter['title'] ?? 'Chapitre')), 0, 250),
                'parts' => array_map(fn ($p) => mb_substr(trim((string) $p), 0, 120), $parts ?: ['Ouverture', 'Développement', 'Mise en pratique']),
            ];
            if ($photos) {
                $visuals = array_slice(array_values((array) ($chapter['visuals'] ?? [])), 0, $photosPer);
                $entry['visuals'] = array_map(fn ($v) => [
                    'caption' => mb_substr(trim((string) ($v['caption'] ?? '')), 0, 200),
                    'desc'    => mb_substr(trim((string) ($v['desc'] ?? '')), 0, 300),
                ], $visuals);
            }
            $toc[] = $entry;
        }

        self::saveDraft((int) $project['id'], $toc);
        return $toc;
    }

    public static function saveDraft(int $projectId, array $toc): void
    {
        Db::run(
            'UPDATE projects SET toc_json = ?, updated_at = ? WHERE id = ?',
            [json_encode($toc, JSON_UNESCAPED_UNICODE), Db::now(), $projectId]
        );
    }

    public static function draft(array $project): array
    {
        $toc = json_decode((string) ($project['toc_json'] ?? ''), true);
        return is_array($toc) ? $toc : [];
    }

    /** Valide le sommaire : crée chapitres, sections et emplacements visuels. */
    public static function validate(array $project): void
    {
        $toc = self::draft($project);
        if (count($toc) < 3) {
            throw new \RuntimeException('Générez d’abord un sommaire.');
        }
        $projectId = (int) $project['id'];
        $wordsTotal = (int) $project['pages'] * (int) Config::get('writing.words_per_page', 285);
        $perChapter = (int) round($wordsTotal / count($toc));
        $sectionsPer = (int) Config::get('writing.sections_per_chapter', 3);

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            Db::run('DELETE FROM chapters WHERE project_id = ?', [$projectId]);
            Db::run('DELETE FROM images WHERE project_id = ?', [$projectId]);

            foreach ($toc as $index => $chapter) {
                $num = $index + 1;
                $chapterId = Db::insert(
                    'INSERT INTO chapters (project_id, num, title, target_words, status) VALUES (?,?,?,?,\'wait\')',
                    [$projectId, $num, $chapter['title'], $perChapter]
                );
                $parts = $chapter['parts'] ?? [];
                for ($s = 1; $s <= $sectionsPer; $s++) {
                    Db::run(
                        'INSERT INTO sections (chapter_id, num, title, status) VALUES (?,?,?,\'wait\')',
                        [$chapterId, $s, $parts[$s - 1] ?? ('Partie ' . $s)]
                    );
                }
                foreach (($chapter['visuals'] ?? []) as $slotIndex => $visual) {
                    Db::run(
                        'INSERT INTO images (project_id, chapter_num, slot, caption, spec, created_at) VALUES (?,?,?,?,?,?)',
                        [
                            $projectId, $num, $slotIndex + 1,
                            trim(($visual['caption'] ?? '') . ($visual['desc'] ? ' — ' . $visual['desc'] : '')),
                            self::imageSpec($project),
                            Db::now(),
                        ]
                    );
                }
            }

            Db::run(
                "UPDATE projects SET writing_status = 'idle', step = GREATEST(step, 4), updated_at = ? WHERE id = ?",
                [Db::now(), $projectId]
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Util::journal($projectId, 'ok', 'Sommaire validé : ' . count($toc) . ' chapitres, ' . Util::nf($wordsTotal) . ' mots visés');
    }

    public static function imageSpec(array $project): string
    {
        $style = match ($project['photo_style'] ?? 'nb') {
            'couleur' => 'Couleur',
            'schemas' => 'Schéma vectoriel N&B',
            default   => 'Niveaux de gris',
        };
        $trim = Config::get('trims.' . ($project['trim_format'] ?? '6x9'), Config::get('trims.6x9'));
        // Largeur utile ≈ largeur rognée − marges ; 300 dpi
        $wPx = (int) round(($trim['w_mm'] - 32) / 25.4 * 300);
        $hPx = (int) round($wPx * 2 / 3);
        return $style . ' · 300 dpi · ' . $wPx . ' × ' . $hPx . ' px';
    }
}
