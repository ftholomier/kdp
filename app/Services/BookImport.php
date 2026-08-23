<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Db;
use App\Core\Util;

/**
 * Import d'un livre existant au format PDF (étape 01).
 *
 * Deux usages :
 *   - « à l'identique » : votre livre est repris tel quel (chapitres, sections,
 *     texte intégral) et vous filez directement à la couverture et à la mise en
 *     page — aucune rédaction IA, aucun crédit consommé ;
 *   - « s'en inspirer » : seul le PLAN est retenu comme point de départ ; le
 *     contenu est ensuite entièrement réécrit par l'IA (parcours normal).
 *
 * L'analyse repose sur PdfText : lignes, corps de police et positions. Les
 * titres se reconnaissent à leur corps nettement supérieur au texte courant
 * (ou à une formulation « Chapitre N »).
 */
final class BookImport
{
    private const FRONT_MATTER = [
        'sommaire', 'table des matières', 'table des matieres', 'copyright',
        'tous droits réservés', 'contents', 'du même auteur', 'à propos de',
        'votre avis compte', 'remerciements', 'mentions légales',
    ];

    /** Analyse le PDF et renvoie la structure détectée (sans rien enregistrer). */
    public static function analyze(string $file): array
    {
        $data = PdfText::extract($file);
        $lines = [];
        foreach ($data['pages'] as $pageLines) {
            foreach ($pageLines as $line) {
                $lines[] = $line;
            }
        }
        if (count($lines) < 10) {
            throw new \RuntimeException('Trop peu de texte détecté : ce PDF est-il bien un livre au format texte ?');
        }

        // Corps du texte = taille la plus fréquente (pondérée par les caractères)
        $weight = [];
        foreach ($lines as $line) {
            $key = (string) (round($line['size'] * 2) / 2);
            $weight[$key] = ($weight[$key] ?? 0) + mb_strlen($line['text']);
        }
        arsort($weight);
        $bodySize = (float) array_key_first($weight);

        // Titres : corps nettement supérieur, ligne courte, ou « Chapitre N »
        $titleMin = $bodySize * 1.22;
        $chapters = [];
        $currentLines = [];
        $title = null;
        $pending = null;      // titre repéré, en attente d'un éventuel sous-titre

        $isFrontMatter = function (string $text): bool {
            $t = mb_strtolower($text);
            foreach (self::FRONT_MATTER as $needle) {
                if (str_contains($t, $needle)) {
                    return true;
                }
            }
            return false;
        };

        foreach ($lines as $line) {
            $text = trim($line['text']);
            if ($text === '' || mb_strlen($text) > 400) {
                continue;
            }
            // Folios et titres courants : lignes très petites et très courtes
            if ($line['size'] < $bodySize * 0.82 && mb_strlen($text) < 60) {
                continue;
            }
            $looksNumbered = (bool) preg_match(
                '/^(chapitre|chapter)\s+(\d+|[IVXLC]{1,6}|un|une|deux|trois|quatre|cinq|six|sept|huit|neuf|dix|onze|douze)\b/iu',
                $text
            );
            $isTitle = (mb_strlen($text) <= 90 && ($line['size'] >= $titleMin || $looksNumbered));

            if ($isTitle) {
                // « Chapitre 3 » seul : le vrai titre est la ligne suivante
                if ($looksNumbered && mb_strlen($text) <= 24) {
                    $pending = $text;
                    continue;
                }
                if ($title !== null) {
                    $chapters[] = ['title' => $title, 'lines' => $currentLines];
                }
                $title = $text;
                $pending = null;
                $currentLines = [];
                continue;
            }
            if ($pending !== null) {
                // ligne courante juste après « Chapitre N » sans titre propre
                if ($title !== null) {
                    $chapters[] = ['title' => $title, 'lines' => $currentLines];
                }
                $title = $pending;
                $pending = null;
                $currentLines = [];
            }
            if ($title !== null) {
                $currentLines[] = $line;
            }
        }
        if ($title !== null) {
            $chapters[] = ['title' => $title, 'lines' => $currentLines];
        }

        // Nettoyage : pages liminaires et chapitres vides écartés
        $chapters = array_values(array_filter($chapters, function ($c) use ($isFrontMatter) {
            $words = 0;
            foreach ($c['lines'] as $l) {
                $words += Util::wordCount($l['text']);
            }
            return $words >= 60 && !$isFrontMatter($c['title']);
        }));

        if (!$chapters) {
            throw new \RuntimeException(
                'Aucun chapitre détecté. Le PDF utilise peut-être une mise en page inhabituelle — '
                . 'essayez « s\'inspirer du livre » : seul le sujet sera repris.'
            );
        }

        $out = [];
        $totalWords = 0;
        foreach ($chapters as $chapter) {
            $sections = self::splitSections($chapter['lines'], $bodySize);
            $words = 0;
            foreach ($sections as $section) {
                $words += Util::wordCount($section['content']);
            }
            $totalWords += $words;
            $out[] = [
                'title'    => mb_substr(self::cleanTitle($chapter['title']), 0, 250),
                'sections' => $sections,
                'words'    => $words,
            ];
        }

        return [
            'chapters'    => $out,
            'words_total' => $totalWords,
            'pages'       => count($data['pages']),
            'body_size'   => $bodySize,
            'title_guess' => self::guessTitle($data['pages'][0] ?? []),
        ];
    }

    /**
     * Découpe le contenu d'un chapitre en sections : sous-titres repérés, ou
     * découpage équilibré en 3 parties si le chapitre n'en comporte pas.
     * @return array<int,array{title:string,content:string}>
     */
    private static function splitSections(array $lines, float $bodySize): array
    {
        $subMin = $bodySize * 1.06;
        $sections = [];
        $title = null;
        $buffer = [];
        foreach ($lines as $line) {
            $isSub = $line['size'] >= $subMin && mb_strlen($line['text']) <= 90;
            if ($isSub) {
                if ($buffer) {
                    $sections[] = ['title' => $title ?? 'Ouverture', 'content' => self::paragraphs($buffer)];
                    $buffer = [];
                }
                $title = self::cleanTitle($line['text']);
                continue;
            }
            $buffer[] = $line;
        }
        if ($buffer) {
            $sections[] = ['title' => $title ?? 'Ouverture', 'content' => self::paragraphs($buffer)];
        }
        // Aucun sous-titre : on équilibre en 3 sections pour rester dans le
        // modèle du studio (3 points de contrôle par chapitre).
        if (count($sections) === 1 && Util::wordCount($sections[0]['content']) > 900) {
            $paragraphs = preg_split('/\n\s*\n/', $sections[0]['content']) ?: [];
            $per = (int) ceil(count($paragraphs) / 3);
            $sections = [];
            foreach (array_chunk($paragraphs, max(1, $per)) as $i => $chunk) {
                $sections[] = ['title' => 'Partie ' . chr(65 + $i), 'content' => implode("\n\n", $chunk)];
            }
        }
        return array_values(array_filter($sections, fn ($s) => Util::wordCount($s['content']) >= 25));
    }

    /** Recolle les lignes en paragraphes (césures et retours de ligne inclus). */
    private static function paragraphs(array $lines): string
    {
        $out = '';
        $previousY = null;
        $gaps = [];
        foreach ($lines as $i => $line) {
            if ($previousY !== null) {
                $gaps[] = abs($previousY - $line['y']);
            }
            $previousY = $line['y'];
        }
        sort($gaps);
        $median = $gaps ? $gaps[(int) floor(count($gaps) / 2)] : 0.0;
        $breakGap = $median > 0 ? $median * 1.6 : 0.0;

        // Alinéa : un début de ligne nettement décalé signale un paragraphe
        $xs = array_map(fn ($l) => $l['x'], $lines);
        $baseX = $xs ? min($xs) : 0.0;

        $previousY = null;
        foreach ($lines as $line) {
            $text = trim($line['text']);
            if ($text === '') {
                continue;
            }
            $indented = ($line['x'] - $baseX) > 6.0;
            $newParagraph = $indented
                || ($previousY !== null && $breakGap > 0 && abs($previousY - $line['y']) > $breakGap);
            $previousY = $line['y'];
            if ($out === '') {
                $out = $text;
                continue;
            }
            if ($newParagraph) {
                $out .= "\n\n" . $text;
                continue;
            }
            // Mot coupé en fin de ligne
            if (preg_match('/\p{L}-$/u', $out)) {
                $out = mb_substr($out, 0, -1) . $text;
            } else {
                $out .= ' ' . $text;
            }
        }
        return trim(preg_replace('/[ \t]+/', ' ', $out) ?? '');
    }

    private static function cleanTitle(string $text): string
    {
        $original = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        // Retire la seule numérotation (« Chapitre 3 : » → « … ») MAIS ne vide
        // jamais le titre : « Partie C » reste « Partie C ».
        $stripped = preg_replace('/^(chapitre|chapter)\s+(\d+|[IVXLC]{1,6})\s*[:.–—-]\s*/iu', '', $original) ?? $original;
        $stripped = trim($stripped);
        return $stripped !== '' ? $stripped : ($original !== '' ? $original : 'Chapitre');
    }

    /** Titre probable du livre : plus grande ligne des premières pages. */
    private static function guessTitle(array $firstPageLines): string
    {
        $best = '';
        $bestSize = 0.0;
        foreach ($firstPageLines as $line) {
            if ($line['size'] > $bestSize && mb_strlen($line['text']) >= 4 && mb_strlen($line['text']) <= 120) {
                $bestSize = $line['size'];
                $best = $line['text'];
            }
        }
        return trim($best);
    }

    /**
     * Retravaille le plan importé selon VOTRE consigne — un seul appel IA, sur
     * les titres uniquement (jamais sur le texte : en mode inspiration il sera
     * rédigé de toute façon à l'étape 05). Si l'IA échoue ou répond de
     * travers, on garde le plan d'origine : l'import n'échoue jamais pour ça.
     *
     * @return array{toc:array,title:string}|null
     */
    private static function rework(array $project, array $toc, string $title, string $brief): ?array
    {
        $langName = Lang::promptName(Lang::codeOf($project));
        $plan = '';
        foreach ($toc as $i => $entry) {
            $plan .= ($i + 1) . '. ' . $entry['title'] . "\n";
            foreach ((array) ($entry['parts'] ?? []) as $part) {
                $plan .= '   - ' . $part . "\n";
            }
        }

        // Le calibre voulu par l'auteur commande aussi le plan retravaillé.
        $shape = Toc::planFor($project);
        $sizeLine = $shape['manual']
            ? "L'auteur veut {$shape['chapters']} chapitres d'environ " . round($shape['pages_each']) . " pages : "
              . "respecte ce découpage, avec {$shape['parts']} sous-parties par chapitre.\n"
            : "Garde 6 à 16 chapitres, 2 à 4 sous-parties chacun.\n";

        try {
            $data = Gemini::json(
                "══════ CONSIGNES DE L'AUTEUR — PRIORITÉ ABSOLUE ══════\n{$brief}\n"
                . "═════════════════════════════════════════════════════\n"
                . "Elles commandent ce plan : elles priment sur le livre importé, sur son titre et sur "
                . "l'ordre de ses chapitres. Applique-les littéralement, point par point.\n\n"
                . "Voici le plan d'un livre existant, importé pour servir de POINT DE DÉPART à un nouveau livre.\n"
                . "Titre actuel : « {$title} »\n\nPLAN ACTUEL :\n{$plan}\n"
                . "Retravaille le plan pour qu'il réponde exactement aux consignes ci-dessus : ajoute, retire, "
                . "fusionne, réordonne ou reformule les chapitres autant que nécessaire — le plan d'origine n'est "
                . "qu'une matière première, pas un modèle à préserver.\n"
                . $sizeLine
                . "Titres concrets et vendeurs, TOUT en {$langName}.\n"
                . 'Réponds UNIQUEMENT en JSON : {"title":"titre du nouveau livre",'
                . '"chapters":[{"title":"…","parts":["…","…","…"]}],"applied":["…"]}' . "\n"
                . "\"applied\" : une ligne par consigne, disant comment tu l'as appliquée au plan.\n"
                . "VÉRIFICATION AVANT DE RÉPONDRE : relis les consignes et contrôle que le plan les applique "
                . "réellement, une par une.",
                ['model' => 'fast', 'temperature' => 0.7, 'timeout' => 90, 'retries' => 1,
                 'system' => "Tu es directeur de collection. Tu structures des livres pratiques en {$langName}. "
                     . "L'auteur t'a donné des consignes explicites : tu les suis à la lettre."]
            );
        } catch (\Throwable $e) {
            Util::journal((int) $project['id'], 'warn',
                'Plan non retravaillé (' . $e->getMessage() . ') — le plan d\'origine est conservé.');
            return null;
        }

        $out = [];
        foreach ((array) ($data['chapters'] ?? []) as $chapter) {
            $chapterTitle = mb_substr(trim((string) ($chapter['title'] ?? '')), 0, 250);
            if ($chapterTitle === '') {
                continue;
            }
            $parts = [];
            foreach ((array) ($chapter['parts'] ?? []) as $part) {
                $part = mb_substr(trim((string) $part), 0, 120);
                if ($part !== '') {
                    $parts[] = $part;
                }
            }
            $out[] = ['title' => $chapterTitle, 'parts' => array_slice($parts, 0, 8)];
        }
        // Un plan à 2 chapitres reste valable si c'est ce que l'auteur a demandé.
        if (count($out) < 2) {
            return null;
        }
        $newTitle = mb_substr(trim((string) ($data['title'] ?? '')), 0, 250);
        return [
            'toc'     => array_slice($out, 0, 40),
            'title'   => $newTitle !== '' ? $newTitle : $title,
            'applied' => Brief::report($data),
        ];
    }

    /**
     * Applique l'analyse au projet en cours.
     * $mode   : 'identique' (contenu repris tel quel, direction la couverture)
     *         | 'inspire'   (seul le plan est retenu ; l'IA réécrit tout)
     * $brief  : VOTRE consigne libre sur ce que vous voulez faire de cet import.
     *           En « inspire », elle transforme réellement le plan (un seul appel
     *           IA) ; dans les deux modes elle devient la ligne éditoriale du
     *           projet, reprise ensuite par la couverture et les métadonnées.
     */
    public static function apply(array $project, array $analysis, string $mode, string $title, string $brief = ''): array
    {
        $projectId = (int) $project['id'];
        $identical = $mode === 'identique';
        $chapters = $analysis['chapters'];
        $title = mb_substr(trim($title) !== '' ? trim($title) : ($analysis['title_guess'] ?: 'Livre importé'), 0, 250);
        $brief = mb_substr(trim($brief), 0, 1200);

        // Le sommaire du studio (brouillon) reprend le plan détecté
        $toc = [];
        foreach ($chapters as $chapter) {
            $toc[] = [
                'title' => $chapter['title'],
                'parts' => array_map(fn ($s) => mb_substr($s['title'], 0, 120), $chapter['sections']),
            ];
        }
        // Consigne + mode inspiration : le plan est retravaillé selon votre
        // demande avant d'atterrir dans le sommaire (titre du livre compris).
        $reworked = false;
        $applied = [];
        if (!$identical && $brief !== '') {
            // La consigne est relue depuis le projet à jour : elle vient d'y être
            // enregistrée et c'est elle qui commandera aussi les étapes suivantes.
            $adapted = self::rework($project + ['brief' => $brief], $toc, $title, $brief);
            if ($adapted) {
                $toc = $adapted['toc'];
                $title = $adapted['title'];
                $applied = $adapted['applied'];
                $reworked = true;
            }
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            Db::run('DELETE FROM chapters WHERE project_id = ?', [$projectId]);
            Db::run('DELETE FROM images WHERE project_id = ?', [$projectId]);

            if ($identical) {
                $num = 1;
                foreach ($chapters as $chapter) {
                    $chapterId = Db::insert(
                        "INSERT INTO chapters (project_id, num, role, title, target_words, status) VALUES (?,?,?,?,?,'done')",
                        [$projectId, $num, 'chapter', $chapter['title'], max(500, (int) $chapter['words'])]
                    );
                    foreach ($chapter['sections'] as $i => $section) {
                        Db::run(
                            "INSERT INTO sections (chapter_id, num, title, status, content, words, updated_at) VALUES (?,?,?,'done',?,?,?)",
                            [$chapterId, $i + 1, mb_substr($section['title'], 0, 250), $section['content'],
                             Util::wordCount($section['content']), Db::now()]
                        );
                    }
                    $num++;
                }
            }

            $pages = max(24, (int) round($analysis['words_total'] / (int) Config::get('writing.words_per_page', 285)));
            Db::run(
                'UPDATE projects SET title = ?, brief = ?, idea = ?, toc_json = ?, pages = ?, writing_status = ?, step = ?, mode = \'describe\', updated_at = ? WHERE id = ?',
                [
                    $title,
                    // VOS CONSIGNES : champ à part entière, réinjecté en tête de
                    // chaque appel à l'IA jusqu'à la fin du parcours. Avant, elles
                    // finissaient noyées dans « idée » et le sommaire les oubliait.
                    $brief,
                    'Livre importé depuis un PDF existant — ' . count($chapters) . ' chapitres, '
                        . Util::nf($analysis['words_total']) . ' mots.',
                    json_encode($toc, JSON_UNESCAPED_UNICODE),
                    min(828, $pages),
                    $identical ? 'done' : 'idle',
                    // Étape ATTEINTE (déverrouille la navigation) : à l'identique le
                    // contenu est complet, donc tout est ouvert jusqu'aux chapitres ;
                    // l'atterrissage sur la couverture est géré par l'interface.
                    $identical ? 6 : 3,
                    Db::now(),
                    $projectId,
                ]
            );

            // Textes de couverture pré-remplis avec le titre repris
            $cover = Db::one('SELECT project_id FROM covers WHERE project_id = ?', [$projectId]);
            if ($cover) {
                $row = Db::one('SELECT texts FROM covers WHERE project_id = ?', [$projectId]);
                $texts = json_decode((string) $row['texts'], true) ?: [];
                $texts['title'] = $title;
                Db::run('UPDATE covers SET texts = ? WHERE project_id = ?', [json_encode($texts, JSON_UNESCAPED_UNICODE), $projectId]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Util::journal(
            $projectId,
            'ok',
            ($identical
                ? 'Livre importé À L\'IDENTIQUE : ' . count($chapters) . ' chapitres, ' . Util::nf($analysis['words_total'])
                  . ' mots repris — direction la couverture et la mise en page.'
                : 'Plan importé pour INSPIRATION : ' . count($toc) . ' chapitres — le contenu sera écrit par l\'IA.')
            . ($brief !== '' ? ' Consignes retenues : « ' . mb_substr($brief, 0, 160) . ' »' : '')
            . ($reworked ? ' · plan retravaillé selon vos consignes' : '')
        );
        if ($applied) {
            Util::journal($projectId, 'ok', 'Vos consignes appliquées au plan : ' . implode(' · ', $applied));
        }
        return [
            'step'     => $identical ? 4 : 3,   // là où l'on vous emmène
            'chapters' => $identical ? count($chapters) : count($toc),
            'words'    => $analysis['words_total'],
            'reworked' => $reworked,
            'applied'  => $applied,
            'title'    => $title,
        ];
    }
}
