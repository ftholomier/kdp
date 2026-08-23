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

        // GARDE-FOU : dès qu'une section est rédigée, la validation ne détruit
        // plus rien — elle SYNCHRONISE (nouveaux chapitres ajoutés, titres
        // alignés, contenu écrit et visuels préservés à l'identique).
        $written = Db::one(
            "SELECT COUNT(*) AS n FROM sections s JOIN chapters c ON c.id = s.chapter_id
             WHERE c.project_id = ? AND s.status = 'done'",
            [$projectId]
        );
        if ((int) $written['n'] > 0) {
            self::syncValidatedToc($project, $toc);
            return;
        }
        $wordsTotal = (int) $project['pages'] * (int) Config::get('writing.words_per_page', 285);
        $perChapter = (int) round($wordsTotal / count($toc));
        $sectionsPer = (int) Config::get('writing.sections_per_chapter', 3);

        \App\Core\Migrations::run();
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            Db::run('DELETE FROM chapters WHERE project_id = ?', [$projectId]);
            Db::run('DELETE FROM images WHERE project_id = ?', [$projectId]);

            // Introduction et conclusion : titres composés par le studio, donc
            // écrits dans la LANGUE DU LIVRE (Lang::labels).
            $labels = Lang::labels(Lang::codeOf($project));

            // Introduction (belle page avant le chapitre 1)
            $introId = Db::insert(
                "INSERT INTO chapters (project_id, num, role, title, target_words, status) VALUES (?,?,?,?,?,'wait')",
                [$projectId, 1, 'intro', $labels['intro'], (int) round($perChapter * 0.6)]
            );
            foreach ([$labels['intro_s1'], $labels['intro_s2']] as $s => $title) {
                Db::run('INSERT INTO sections (chapter_id, num, title, status) VALUES (?,?,?,\'wait\')', [$introId, $s + 1, $title]);
            }

            foreach ($toc as $index => $chapter) {
                $num = $index + 2; // décalé d'un cran par l'introduction
                $chapterId = Db::insert(
                    "INSERT INTO chapters (project_id, num, role, title, target_words, status) VALUES (?,?,?,?,?,'wait')",
                    [$projectId, $num, 'chapter', $chapter['title'], $perChapter]
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

            // Conclusion (synthèse + plan d'action)
            $conclusionId = Db::insert(
                "INSERT INTO chapters (project_id, num, role, title, target_words, status) VALUES (?,?,?,?,?,'wait')",
                [$projectId, count($toc) + 2, 'conclusion', $labels['conclusion'], (int) round($perChapter * 0.5)]
            );
            foreach ([$labels['concl_s1'], $labels['concl_s2']] as $s => $title) {
                Db::run('INSERT INTO sections (chapter_id, num, title, status) VALUES (?,?,?,\'wait\')', [$conclusionId, $s + 1, $title]);
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

        Util::journal($projectId, 'ok', 'Sommaire validé : introduction + ' . count($toc) . ' chapitres + conclusion, ' . Util::nf($wordsTotal) . ' mots visés');
    }

    /**
     * Ajoute un chapitre À LA DEMANDE (« ajoute un chapitre sur… »), à tout
     * moment : sur le brouillon de sommaire si rien n'est validé, ou DANS le
     * livre déjà structuré (chapitres/sections/visuels créés, conclusion
     * décalée) — même si la rédaction est terminée : le nouveau chapitre
     * repart en attente d'écriture à l'étape 05.
     */
    public static function addChapter(array $project, ?array $concept, string $request): array
    {
        $request = trim($request);
        if (mb_strlen($request) < 8) {
            throw new \RuntimeException('Décrivez le chapitre souhaité (ex. : « un chapitre sur 50 recettes originales »).');
        }
        $projectId = (int) $project['id'];
        $sectionsPer = (int) Config::get('writing.sections_per_chapter', 3);
        $photos = !empty($project['photos']);
        $photosPer = (int) $project['photos_per'];

        // Contexte : sommaire actuel (brouillon ou chapitres réels)
        $existing = array_map(
            fn ($c) => (string) $c['title'],
            Db::all("SELECT title FROM chapters WHERE project_id = ? AND role = 'chapter' ORDER BY num", [$projectId])
        );
        $live = count($existing) > 0;
        $draft = self::draft($project);
        if (!$live) {
            $existing = array_map(fn ($c) => (string) $c['title'], $draft);
        }

        $visualSpec = $photos
            ? ",\"visuals\":[{\"caption\":\"légende courte\",\"desc\":\"contenu précis du visuel\"}] (exactement {$photosPer} entrées)"
            : '';
        $prompt = "Tu es directeur éditorial. Un livre pratique en français est en cours :\n"
            . 'Titre : « ' . ($concept['title'] ?? $project['title']) . " »\n"
            . "Ton : {$project['tone']}.\n"
            . 'Chapitres existants : ' . ($existing ? '« ' . implode(' » · « ', array_slice($existing, 0, 20)) . ' »' : 'aucun') . "\n\n"
            . "L'auteur demande d'AJOUTER ce chapitre : « {$request} »\n\n"
            . "Réponds UNIQUEMENT avec un objet JSON valide :\n"
            . '{"title":"titre évocateur du chapitre (max 70 caractères, sans numérotation)",'
            . '"parts":["...","...","..."] (exactement ' . $sectionsPer . ' sous-parties courtes de 3 à 6 mots)'
            . $visualSpec . '}' . "\n"
            . "Le chapitre doit répondre exactement à la demande de l'auteur, dans le ton du livre, sans doublonner les chapitres existants.";

        $data = Gemini::json($prompt, [
            'model'       => 'fast',
            'temperature' => 0.8,
            'search'      => false,
            'system'      => 'Tu construis des sommaires de livres pratiques impeccables. Réponse en français.',
        ]);
        $title = mb_substr(trim((string) ($data['title'] ?? '')), 0, 250);
        if ($title === '') {
            throw new \RuntimeException('Le chapitre généré est vide, reformulez votre demande.');
        }
        $parts = array_map(
            fn ($p) => mb_substr(trim((string) $p), 0, 120),
            array_slice(array_values((array) ($data['parts'] ?? [])), 0, $sectionsPer)
        );
        while (count($parts) < $sectionsPer) {
            $parts[] = 'Partie ' . (count($parts) + 1);
        }
        $entry = ['title' => $title, 'parts' => $parts];
        if ($photos) {
            $visuals = array_slice(array_values((array) ($data['visuals'] ?? [])), 0, $photosPer);
            $entry['visuals'] = array_map(fn ($v) => [
                'caption' => mb_substr(trim((string) ($v['caption'] ?? $title)), 0, 200),
                'desc'    => mb_substr(trim((string) ($v['desc'] ?? '')), 0, 300),
            ], $visuals ?: array_fill(0, $photosPer, ['caption' => $title, 'desc' => '']));
        }

        // Brouillon de sommaire pas encore validé : simple ajout au brouillon
        $draft[] = $entry;
        self::saveDraft($projectId, $draft);
        if (!$live) {
            Util::journal($projectId, 'ok', 'Chapitre ajouté au sommaire : « ' . $title . ' »');
            return ['live' => false, 'toc' => $draft, 'title' => $title];
        }

        // Livre déjà structuré : insertion réelle avant la conclusion
        $displayNum = self::insertLiveChapter($project, $entry);
        Util::journal($projectId, 'ok', 'Chapitre ' . $displayNum . ' inséré à la demande : « ' . $title . ' » — à rédiger à l\'étape 05');
        return ['live' => true, 'toc' => $draft, 'title' => $title];
    }

    /**
     * Insère UN chapitre (titre + sous-parties + emplacements visuels) dans un
     * livre déjà structuré, juste avant la conclusion, sans rien toucher au
     * contenu existant. Retourne le numéro affiché du chapitre.
     */
    private static function insertLiveChapter(array $project, array $entry): int
    {
        $projectId = (int) $project['id'];
        $title = mb_substr(trim((string) ($entry['title'] ?? 'Chapitre')), 0, 250);
        $sectionsPer = (int) Config::get('writing.sections_per_chapter', 3);
        $parts = array_slice(array_values((array) ($entry['parts'] ?? [])), 0, $sectionsPer);
        while (count($parts) < $sectionsPer) {
            $parts[] = 'Partie ' . (count($parts) + 1);
        }
        $visuals = array_values((array) ($entry['visuals'] ?? []));
        if (!empty($project['photos']) && !$visuals) {
            $visuals = array_fill(0, max(1, (int) $project['photos_per']), ['caption' => 'Visuel du chapitre — ' . $title, 'desc' => '']);
        }
        $last = Db::one("SELECT COALESCE(MAX(num), 1) AS n FROM chapters WHERE project_id = ? AND role = 'chapter'", [$projectId]);
        $num = (int) $last['n'] + 1;
        $avg = Db::one("SELECT COALESCE(ROUND(AVG(target_words)), 2000) AS w FROM chapters WHERE project_id = ? AND role = 'chapter'", [$projectId]);

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            Db::run('UPDATE chapters SET num = num + 1 WHERE project_id = ? AND num >= ?', [$projectId, $num]);
            Db::run('UPDATE images SET chapter_num = chapter_num + 1 WHERE project_id = ? AND chapter_num >= ?', [$projectId, $num]);
            $chapterId = Db::insert(
                "INSERT INTO chapters (project_id, num, role, title, target_words, status) VALUES (?,?,?,?,?,'wait')",
                [$projectId, $num, 'chapter', $title, (int) $avg['w']]
            );
            foreach ($parts as $s => $partTitle) {
                Db::run('INSERT INTO sections (chapter_id, num, title, status) VALUES (?,?,?,\'wait\')', [$chapterId, $s + 1, $partTitle]);
            }
            foreach ($visuals as $slotIndex => $visual) {
                Db::run(
                    'INSERT INTO images (project_id, chapter_num, slot, caption, spec, created_at) VALUES (?,?,?,?,?,?)',
                    [
                        $projectId, $num, $slotIndex + 1,
                        trim(($visual['caption'] ?? '') . (!empty($visual['desc']) ? ' — ' . $visual['desc'] : '')),
                        self::imageSpec($project),
                        Db::now(),
                    ]
                );
            }
            // La rédaction repart : le chapitre neuf attend à l'étape 05
            Db::run(
                "UPDATE projects SET writing_status = IF(writing_status = 'done', 'paused', writing_status), updated_at = ? WHERE id = ?",
                [Db::now(), $projectId]
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $num - 1;
    }

    /**
     * SUPPRIME un chapitre du sommaire — geste volontaire, à l'inverse de la
     * validation qui, elle, ne détruit jamais rien. Retire l'entrée du
     * brouillon et, si le livre est déjà structuré, le chapitre correspondant
     * avec ses sections, son texte et ses emplacements visuels ; la numérotation
     * (et donc la conclusion) se resserre derrière lui.
     *
     * @return array{title:string,words:int,live:bool,toc:array}
     */
    public static function deleteChapter(array $project, int $index): array
    {
        $projectId = (int) $project['id'];
        $toc = array_values(self::draft($project));
        if (!isset($toc[$index])) {
            throw new \RuntimeException('Chapitre introuvable dans le sommaire.');
        }
        $entry = $toc[$index];
        $title = trim((string) ($entry['title'] ?? ''));
        array_splice($toc, $index, 1);

        // Chapitre correspondant dans le livre structuré : d'abord par titre
        // exact, sinon par position parmi les chapitres (hors intro/conclusion).
        $chapters = Db::all(
            "SELECT id, num, title FROM chapters WHERE project_id = ? AND role = 'chapter' ORDER BY num",
            [$projectId]
        );
        $target = null;
        foreach ($chapters as $chapter) {
            if (mb_strtolower(trim((string) $chapter['title'])) === mb_strtolower($title)) {
                $target = $chapter;
                break;
            }
        }
        if (!$target && isset($chapters[$index])) {
            $target = $chapters[$index];
        }

        $words = 0;
        if ($target) {
            $row = Db::one(
                'SELECT COALESCE(SUM(words), 0) AS w FROM sections WHERE chapter_id = ?',
                [(int) $target['id']]
            );
            $words = (int) ($row['w'] ?? 0);

            $pdo = Db::pdo();
            $pdo->beginTransaction();
            try {
                $num = (int) $target['num'];
                Db::run('DELETE FROM sections WHERE chapter_id = ?', [(int) $target['id']]);
                Db::run('DELETE FROM images WHERE project_id = ? AND chapter_num = ?', [$projectId, $num]);
                Db::run('DELETE FROM chapters WHERE id = ?', [(int) $target['id']]);
                // On resserre la numérotation : la conclusion remonte d'un cran.
                Db::run('UPDATE chapters SET num = num - 1 WHERE project_id = ? AND num > ?', [$projectId, $num]);
                Db::run('UPDATE images SET chapter_num = chapter_num - 1 WHERE project_id = ? AND chapter_num > ?', [$projectId, $num]);
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        self::saveDraft($projectId, $toc);
        Util::journal(
            $projectId,
            'ok',
            'Chapitre supprimé : « ' . $title . ' »'
                . ($words > 0 ? ' — ' . Util::nf($words) . ' mots rédigés retirés du livre' : '')
        );
        return ['title' => $title, 'words' => $words, 'live' => $target !== null, 'toc' => $toc];
    }

    /**
     * Validation d'un sommaire sur un livre DÉJÀ rédigé : synchronisation
     * douce. Les chapitres existants (et leur contenu) sont préservés ; les
     * entrées inconnues du brouillon deviennent de nouveaux chapitres ; si le
     * brouillon compte autant d'entrées que le livre, les écarts de titres
     * sont traités comme des renommages. Les SOUS-PARTIES suivent aussi :
     * titres alignés, parties ajoutées, parties retirées uniquement quand
     * elles sont encore vides. Aucun texte rédigé n'est jamais supprimé ici
     * (la suppression volontaire passe par deleteChapter()).
     */
    private static function syncValidatedToc(array $project, array $toc): void
    {
        $projectId = (int) $project['id'];
        $existing = Db::all(
            "SELECT id, num, title FROM chapters WHERE project_id = ? AND role = 'chapter' ORDER BY num",
            [$projectId]
        );
        $pool = $existing;
        $toInsert = [];
        foreach ($toc as $entry) {
            $title = mb_strtolower(trim((string) ($entry['title'] ?? '')));
            $foundKey = null;
            foreach ($pool as $k => $chapter) {
                if (mb_strtolower(trim((string) $chapter['title'])) === $title) {
                    $foundKey = $k;
                    break;
                }
            }
            if ($foundKey !== null) {
                unset($pool[$foundKey]);
            } else {
                $toInsert[] = $entry;
            }
        }

        if (count($toc) === count($existing) && $toInsert) {
            // Mêmes chapitres, titres retouchés : alignement par position
            foreach (array_values($toc) as $i => $entry) {
                $t = mb_substr(trim((string) ($entry['title'] ?? '')), 0, 250);
                if ($t !== '' && isset($existing[$i]) && $t !== trim((string) $existing[$i]['title'])) {
                    Db::run('UPDATE chapters SET title = ? WHERE id = ?', [$t, (int) $existing[$i]['id']]);
                }
            }
            Util::journal($projectId, 'ok', 'Sommaire synchronisé : titres mis à jour · contenu rédigé intact');
        } elseif ($toInsert) {
            foreach ($toInsert as $entry) {
                self::insertLiveChapter($project, $entry);
            }
            Util::journal($projectId, 'ok', 'Sommaire synchronisé : ' . count($toInsert) . ' chapitre(s) ajouté(s) · contenu rédigé intact');
        } else {
            Util::journal($projectId, 'dim', 'Sommaire déjà à jour : contenu rédigé intact');
        }

        self::syncSectionTitles($project, $toc);
        self::syncImageSlots($project, $toc);
        Db::run('UPDATE projects SET step = GREATEST(step, 4), updated_at = ? WHERE id = ?', [Db::now(), $projectId]);
    }

    /**
     * Aligne les SOUS-PARTIES du livre sur celles du brouillon : renommages
     * appliqués, parties ajoutées (en attente de rédaction), parties retirées
     * seulement si elles n'ont pas encore de texte. Une section déjà rédigée
     * n'est jamais perdue — au pire elle est renommée.
     */
    private static function syncSectionTitles(array $project, array $toc): void
    {
        $projectId = (int) $project['id'];
        $partsByTitle = [];
        foreach ($toc as $entry) {
            $parts = array_values(array_filter(array_map(
                fn ($p) => mb_substr(trim((string) $p), 0, 250),
                (array) ($entry['parts'] ?? [])
            ), fn ($p) => $p !== ''));
            if ($parts) {
                $partsByTitle[mb_strtolower(trim((string) ($entry['title'] ?? '')))] = $parts;
            }
        }
        if (!$partsByTitle) {
            return;
        }

        $renamed = 0;
        $added = 0;
        $dropped = 0;
        $chapters = Db::all(
            "SELECT id, title FROM chapters WHERE project_id = ? AND role = 'chapter' ORDER BY num",
            [$projectId]
        );
        foreach ($chapters as $chapter) {
            $parts = $partsByTitle[mb_strtolower(trim((string) $chapter['title']))] ?? null;
            if ($parts === null) {
                continue;
            }
            $sections = Db::all(
                'SELECT id, num, title, content FROM sections WHERE chapter_id = ? ORDER BY num',
                [(int) $chapter['id']]
            );
            foreach ($parts as $i => $part) {
                if (isset($sections[$i])) {
                    if (trim((string) $sections[$i]['title']) !== $part) {
                        Db::run('UPDATE sections SET title = ? WHERE id = ?', [$part, (int) $sections[$i]['id']]);
                        $renamed++;
                    }
                    continue;
                }
                Db::run(
                    "INSERT INTO sections (chapter_id, num, title, status) VALUES (?,?,?,'wait')",
                    [(int) $chapter['id'], $i + 1, $part]
                );
                $added++;
            }
            // Parties retirées du brouillon : supprimées uniquement si vides
            foreach (array_slice($sections, count($parts)) as $extra) {
                if (trim((string) ($extra['content'] ?? '')) === '') {
                    Db::run('DELETE FROM sections WHERE id = ?', [(int) $extra['id']]);
                    $dropped++;
                }
            }
        }
        if ($renamed || $added || $dropped) {
            Util::journal($projectId, 'ok', 'Sous-parties synchronisées : ' . $renamed . ' renommée(s), '
                . $added . ' ajoutée(s), ' . $dropped . ' vide(s) retirée(s) · texte rédigé intact');
        }
    }

    /**
     * Aligne les emplacements visuels sur les réglages ACTUELS (photos on/off,
     * nombre par chapitre, style) sans jamais réécrire le texte : activer les
     * photos après rédaction ajoute juste les emplacements — seule la mise en
     * page change, zéro appel d'IA de rédaction.
     */
    private static function syncImageSlots(array $project, array $toc): void
    {
        $projectId = (int) $project['id'];
        $photos = !empty($project['photos']);
        $photosPer = max(1, (int) $project['photos_per']);
        $spec = self::imageSpec($project);

        $visualsByTitle = [];
        foreach ($toc as $entry) {
            $visualsByTitle[mb_strtolower(trim((string) ($entry['title'] ?? '')))] = array_values((array) ($entry['visuals'] ?? []));
        }

        $added = 0;
        $removed = 0;
        $chapters = Db::all(
            "SELECT num, title FROM chapters WHERE project_id = ? AND role = 'chapter' ORDER BY num",
            [$projectId]
        );
        foreach ($chapters as $chapter) {
            $num = (int) $chapter['num'];
            $slots = Db::all(
                'SELECT id, slot, filename FROM images WHERE project_id = ? AND chapter_num = ? ORDER BY slot',
                [$projectId, $num]
            );
            if (!$photos) {
                // Photos désactivées : on retire les emplacements VIDES, jamais
                // une image déjà fournie ou générée.
                foreach ($slots as $slot) {
                    if (empty($slot['filename'])) {
                        Db::run('DELETE FROM images WHERE id = ?', [(int) $slot['id']]);
                        $removed++;
                    }
                }
                continue;
            }
            // Emplacements vides au-delà du nombre demandé : retirés
            foreach ($slots as $slot) {
                if ((int) $slot['slot'] > $photosPer && empty($slot['filename'])) {
                    Db::run('DELETE FROM images WHERE id = ?', [(int) $slot['id']]);
                    $removed++;
                }
            }
            // Spec (style/format) rafraîchie sur les emplacements encore vides
            Db::run(
                "UPDATE images SET spec = ? WHERE project_id = ? AND chapter_num = ? AND (filename IS NULL OR filename = '')",
                [$spec, $projectId, $num]
            );
            $have = [];
            foreach ($slots as $slot) {
                if ((int) $slot['slot'] <= $photosPer || !empty($slot['filename'])) {
                    $have[] = (int) $slot['slot'];
                }
            }
            $visuals = $visualsByTitle[mb_strtolower(trim((string) $chapter['title']))] ?? [];
            for ($n = 1; $n <= $photosPer; $n++) {
                if (in_array($n, $have, true)) {
                    continue;
                }
                $visual = $visuals[$n - 1] ?? null;
                $caption = $visual
                    ? trim((string) ($visual['caption'] ?? '') . (!empty($visual['desc']) ? ' — ' . $visual['desc'] : ''))
                    : ('Visuel du chapitre — ' . $chapter['title']);
                Db::run(
                    'INSERT INTO images (project_id, chapter_num, slot, caption, spec, created_at) VALUES (?,?,?,?,?,?)',
                    [$projectId, $num, $n, $caption, $spec, Db::now()]
                );
                $added++;
            }
        }
        if ($added || $removed) {
            Util::journal($projectId, 'ok', 'Visuels synchronisés : ' . $added . ' emplacement(s) ajouté(s)'
                . ($removed ? ', ' . $removed . ' retiré(s)' : '') . ' · texte rédigé intact, zéro réécriture');
        }
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
