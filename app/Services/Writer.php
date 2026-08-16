<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Db;
use App\Core\Util;

/**
 * Étape 5 — Rédaction : moteur à points de contrôle.
 *
 * Le navigateur appelle write/tick en boucle : chaque tick rédige UNE section
 * (l'unité de checkpoint) puis l'enregistre. Si un appel échoue (réseau,
 * quota, timeout), rien n'est perdu : la section repasse en attente et le
 * tick suivant reprend exactement au même point — aucun doublon possible.
 * Conçu pour l'hébergement mutualisé (aucun processus long côté serveur).
 */
final class Writer
{
    /** Compte les sections planifiées et déjà rédigées du projet. */
    private static function totals(int $projectId): array
    {
        $row = Db::one(
            "SELECT COUNT(s.id) AS sections, COALESCE(SUM(s.status = 'done'), 0) AS done
             FROM chapters c JOIN sections s ON s.chapter_id = c.id
             WHERE c.project_id = ?",
            [$projectId]
        );
        return ['sections' => (int) ($row['sections'] ?? 0), 'done' => (int) ($row['done'] ?? 0)];
    }

    public static function start(array $project): void
    {
        $projectId = (int) $project['id'];
        $total = self::totals($projectId);
        if ($total['sections'] === 0) {
            throw new \RuntimeException('Validez d’abord le sommaire (étape 3).');
        }
        Db::run("UPDATE projects SET writing_status = 'running', step = GREATEST(step, 5), updated_at = ? WHERE id = ?", [Db::now(), $projectId]);
        if ($total['done'] === 0) {
            Util::journal($projectId, 'ok', 'Rédaction lancée · ' . $total['sections'] . ' sections planifiées');
        } else {
            Util::journal($projectId, 'ok', 'Reprise à partir du dernier paragraphe validé');
        }
    }

    public static function pause(array $project): void
    {
        Db::run("UPDATE projects SET writing_status = 'paused', updated_at = ? WHERE id = ?", [Db::now(), (int) $project['id']]);
        Util::journal((int) $project['id'], 'dim', 'Rédaction mise en pause');
    }

    /** Rédige la prochaine section en attente. Retourne l'état complet. */
    public static function tick(array $project, ?array $concept): array
    {
        $projectId = (int) $project['id'];

        if ($project['writing_status'] !== 'running') {
            return self::status($project);
        }

        $section = Db::one(
            "SELECT s.*, c.num AS chapter_num, c.role AS chapter_role, c.title AS chapter_title, c.target_words, c.id AS chap_id
             FROM sections s JOIN chapters c ON c.id = s.chapter_id
             WHERE c.project_id = ? AND s.status != 'done'
             ORDER BY c.num, s.num LIMIT 1",
            [$projectId]
        );

        if (!$section) {
            Db::run("UPDATE projects SET writing_status = 'done', step = GREATEST(step, 6), updated_at = ? WHERE id = ?", [Db::now(), $projectId]);
            Util::journal($projectId, 'ok', 'Rédaction terminée · manuscrit complet enregistré');
            self::notifyDone($project);
            return self::status(self::freshProject($projectId));
        }

        $checkpoint = 'ch' . $section['chapter_num'] . ' §' . $section['num'];
        $label = self::labelFor($projectId, (string) ($section['chapter_role'] ?? 'chapter'), (int) $section['chapter_num']);
        // VERROU : revendique la section — si un autre processus (cron et
        // navigateur en parallèle) l'a déjà prise, on passe son tour.
        $claim = Db::pdo()->prepare("UPDATE sections SET status = 'writing' WHERE id = ? AND status = 'wait'");
        $claim->execute([(int) $section['id']]);
        if ($claim->rowCount() === 0 && $section['status'] !== 'writing') {
            return self::status(self::freshProject($projectId));
        }
        Db::run("UPDATE chapters SET status = 'writing' WHERE id = ? AND status = 'wait'", [$section['chap_id']]);
        Util::journal($projectId, 'ok', $label . ' · section ' . $section['num'] . ' — rédaction');

        try {
            $text = self::writeSection($project, $concept, $section);
        } catch (\Throwable $e) {
            // Point de contrôle : la section revient en attente, rien n'est perdu.
            Db::run("UPDATE sections SET status = 'wait' WHERE id = ?", [$section['id']]);
            Util::journal($projectId, 'warn', '⚠ Réponse du modèle interrompue (' . mb_substr($e->getMessage(), 0, 120) . ')');
            Util::journal($projectId, 'warn', 'Point de contrôle restauré : ' . $checkpoint);
            throw new \RuntimeException('Interruption pendant ' . $checkpoint . ' — reprise automatique au prochain passage.', 0, $e);
        }

        $words = Util::wordCount($text);
        Db::run(
            "UPDATE sections SET status = 'done', content = ?, words = ?, updated_at = ? WHERE id = ?",
            [$text, $words, Db::now(), $section['id']]
        );
        Util::journal($projectId, 'ok', $label . ' · section ' . $section['num'] . ' — terminée (' . Util::nf($words) . ' mots)');

        // Cohérence longueur vs sommaire
        $target = (int) round($section['target_words'] / max(1, (int) Config::get('writing.sections_per_chapter', 3)));
        if ($target > 0 && abs($words - $target) / $target > 0.45) {
            Util::journal($projectId, 'dim', 'Longueur hors cible (' . Util::nf($words) . ' vs ' . Util::nf($target) . ' mots) — sera rééquilibrée');
        } else {
            Util::journal($projectId, 'dim', 'Contrôle de cohérence avec le sommaire : conforme');
        }

        // Fin de chapitre : déduplication naïve + visuels réservés
        $remaining = Db::one(
            "SELECT COUNT(*) AS n FROM sections WHERE chapter_id = ? AND status != 'done'",
            [$section['chap_id']]
        );
        if ((int) $remaining['n'] === 0) {
            Db::run("UPDATE chapters SET status = 'done' WHERE id = ?", [$section['chap_id']]);
            $duplicates = self::duplicateParagraphs((int) $section['chap_id']);
            Util::journal($projectId, 'dim', 'Déduplication : ' . $duplicates . ' paragraphe' . ($duplicates > 1 ? 's' : '') . ' redondant' . ($duplicates > 1 ? 's' : ''));
            if (!empty($project['photos'])) {
                $slots = Db::one('SELECT COUNT(*) AS n FROM images WHERE project_id = ? AND chapter_num = ?', [$projectId, $section['chapter_num']]);
                if ((int) $slots['n'] > 0) {
                    Util::journal($projectId, 'dim', 'Emplacement' . ((int) $slots['n'] > 1 ? 's' : '') . ' visuel' . ((int) $slots['n'] > 1 ? 's' : '') . ' ' . $section['chapter_num'] . '.1–' . $section['chapter_num'] . '.' . $slots['n'] . ' réservé' . ((int) $slots['n'] > 1 ? 's' : ''));
                }
            }
            Util::journal($projectId, 'ok', $label . ' — terminé(e) · sauvegarde');
        }

        return self::status(self::freshProject($projectId));
    }

    /** État complet de la rédaction (progression, chapitres, checkpoint). */
    public static function status(array $project): array
    {
        $projectId = (int) $project['id'];
        $chapters = Db::all(
            "SELECT c.id, c.num, c.role, c.title, c.status, c.target_words,
                    COALESCE(SUM(CASE WHEN s.status = 'done' THEN s.words END), 0) AS words_done,
                    COUNT(s.id) AS sections_total,
                    SUM(s.status = 'done') AS sections_done
             FROM chapters c LEFT JOIN sections s ON s.chapter_id = c.id
             WHERE c.project_id = ? GROUP BY c.id ORDER BY c.num",
            [$projectId]
        );

        $sectionsTotal = 0;
        $sectionsDone = 0;
        $wordsDone = 0;
        $current = null;
        foreach ($chapters as $chapter) {
            $sectionsTotal += (int) $chapter['sections_total'];
            $sectionsDone += (int) $chapter['sections_done'];
            $wordsDone += (int) $chapter['words_done'];
            if ($current === null && $chapter['status'] !== 'done') {
                $current = $chapter;
            }
        }
        $progress = $sectionsTotal > 0 ? (int) floor($sectionsDone / $sectionsTotal * 100) : 0;

        $checkpoint = '—';
        if ($current) {
            $nextSection = Db::one(
                "SELECT num FROM sections WHERE chapter_id = ? AND status != 'done' ORDER BY num LIMIT 1",
                [$current['id']]
            );
            $checkpoint = 'ch' . $current['num'] . ' §' . ($nextSection['num'] ?? 1);
        }

        // Pagination indicative en direct (le PDF final et le champ
        // « Pages définitives » restent la référence pour les exports)
        $figures = 0;
        if (!empty($project['photos'])) {
            $f = Db::one('SELECT COUNT(*) AS n FROM images WHERE project_id = ?', [$projectId]);
            $figures = (int) $f['n'];
        }
        $pagesEst = $wordsDone > 0 ? Layout::estimatePages($wordsDone, count($chapters), $figures) : 0;

        $secondsPer = (int) Config::get('writing.seconds_per_section', 40);
        $chapterIndex = 0;
        $currentLabel = '—';
        $chaptersOut = array_map(function ($c) use (&$chapterIndex, &$currentLabel, $current) {
            $role = (string) ($c['role'] ?? 'chapter');
            if ($role === 'chapter') {
                $chapterIndex++;
            }
            $label = match ($role) {
                'intro'      => 'Introduction',
                'conclusion' => 'Conclusion',
                default      => 'Chapitre ' . $chapterIndex,
            };
            $short = match ($role) {
                'intro'      => 'INTRO',
                'conclusion' => 'CONCL',
                default      => 'CH ' . str_pad((string) $chapterIndex, 2, '0', STR_PAD_LEFT),
            };
            if ($current && (int) $c['num'] === (int) $current['num']) {
                $currentLabel = $label;
            }
            return [
                'num'           => (int) $c['num'],
                'role'          => $role,
                'label'         => $label,
                'short'         => $short,
                'title'         => $c['title'],
                'status'        => $c['status'],
                'words_done'    => (int) $c['words_done'],
                'words_target'  => (int) $c['target_words'],
                'pct'           => (int) $c['sections_total'] > 0 ? (int) round((int) $c['sections_done'] / (int) $c['sections_total'] * 100) : 0,
            ];
        }, $chapters);

        return [
            'writing_status' => $project['writing_status'],
            'progress'       => $progress,
            'words_done'     => $wordsDone,
            'pages_est'      => $pagesEst,
            'chapter_current'=> $current ? (int) $current['num'] : count($chapters),
            'chapter_total'  => count($chapters),
            'current_label'  => $current ? $currentLabel : '—',
            'eta_min'        => (int) max(1, ceil(($sectionsTotal - $sectionsDone) * $secondsPer / 60)),
            'checkpoint'     => $checkpoint,
            'chapters'       => $chaptersOut,
        ];
    }

    public static function journalSince(int $projectId, int $afterId): array
    {
        return Db::all(
            'SELECT id, level, message, DATE_FORMAT(created_at, "%H:%i:%s") AS t
             FROM journal WHERE project_id = ? AND id > ? ORDER BY id LIMIT 200',
            [$projectId, $afterId]
        );
    }

    /**
     * RETOUCHE une section déjà rédigée selon une consigne libre de l'auteur
     * (« plus court », « ajoute un exemple chiffré »…). Un seul appel IA,
     * la section garde son statut : rien d'autre n'est réécrit.
     */
    public static function retouch(array $project, array $section, string $instruction): string
    {
        $instruction = trim($instruction);
        if (mb_strlen($instruction) < 4) {
            throw new \RuntimeException('Précisez la retouche souhaitée (ex. : « raccourcis d\'un tiers »).');
        }
        $current = trim((string) ($section['content'] ?? ''));
        if ($current === '') {
            throw new \RuntimeException('Cette section n\'est pas encore rédigée (lancez l\'étape 05).');
        }
        $calloutRule = "Encadrés : conserve la SYNTAXE EXACTE si tu en utilises —\n:::conseil\nTexte…\n:::\n"
            . "(types autorisés : retenir, chiffre, conseil, exemple, faq, attention). Aucune autre mise en forme, pas de titres markdown.";

        $prompt = "Tu es directeur littéraire. Voici une section du chapitre « {$section['chapter_title']} » "
            . "(section « {$section['title']} ») d'un livre pratique en français, ton « {$project['tone']} ».\n\n"
            . "TEXTE ACTUEL :\n---\n" . $current . "\n---\n\n"
            . "CONSIGNE DE L'AUTEUR (prioritaire) : « {$instruction} »\n\n"
            . "Réécris la section en appliquant précisément cette consigne, en conservant ce qui fonctionne, "
            . "le même ton et une longueur comparable sauf si la consigne en décide autrement.\n"
            . $calloutRule . "\n"
            . "Réponds UNIQUEMENT avec le texte réécrit de la section, sans commentaire ni titre.";

        $text = trim(Gemini::text($prompt, [
            'model'       => 'pro',
            'temperature' => 0.7,
            'timeout'     => 75,
            'retries'     => 0,
            'system'      => 'Tu réécris des sections de livres pratiques impeccables, en français.',
        ]));
        if (Util::wordCount($text) < 60) {
            throw new \RuntimeException('Réécriture trop courte — reformulez la consigne et relancez.');
        }
        Db::run(
            "UPDATE sections SET content = ?, words = ?, updated_at = ? WHERE id = ?",
            [$text, Util::wordCount($text), Db::now(), (int) $section['id']]
        );
        Util::journal((int) $project['id'], 'ok', 'Section « ' . $section['title'] . ' » retouchée : ' . mb_substr($instruction, 0, 80));
        return $text;
    }

    /** E-mail « manuscrit terminé » (utile surtout en écriture autonome cron). */
    private static function notifyDone(array $project): void
    {
        $user = Db::one('SELECT email, display_name FROM users WHERE id = ?', [(int) $project['user_id']]);
        $to = trim((string) \App\Core\Settings::get('notify.email', (string) ($user['email'] ?? '')));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $title = (string) $project['title'];
        @mail(
            $to,
            '=?UTF-8?B?' . base64_encode('📖 Manuscrit terminé — ' . mb_substr($title, 0, 60)) . '?=',
            "Bonne nouvelle : la rédaction de « {$title} » est terminée.\n\n"
            . "Prochaines étapes : relisez les chapitres (étape 06), puis exportez le PDF intérieur, l'EPUB et la couverture (étape 07).\n\n— "
            . (string) \App\Core\Config::get('app.name', 'Tirage'),
            "Content-Type: text/plain; charset=UTF-8\r\n"
        );
    }

    /**
     * RELECTURE d'un chapitre entier (orthographe, répétitions, transitions) :
     * un appel IA par chapitre, sections renvoyées corrigées une à une.
     */
    public static function proofreadChapter(array $project, int $chapterNum): array
    {
        $chapter = Db::one('SELECT * FROM chapters WHERE project_id = ? AND num = ?', [(int) $project['id'], $chapterNum]);
        if (!$chapter) {
            throw new \RuntimeException('Chapitre introuvable.');
        }
        $sections = Db::all("SELECT id, num, title, content FROM sections WHERE chapter_id = ? AND status = 'done' AND content IS NOT NULL ORDER BY num", [(int) $chapter['id']]);
        if (!$sections) {
            return ['corrected' => 0, 'label' => $chapter['title']];
        }
        $blob = '';
        foreach ($sections as $section) {
            $blob .= "<<<SECTION {$section['num']}>>>\n" . trim((string) $section['content']) . "\n<<<FIN>>>\n\n";
        }
        $prompt = "RELECTURE PROFESSIONNELLE du chapitre « {$chapter['title']} » d'un livre pratique français.\n"
            . "Corrige UNIQUEMENT : orthographe, grammaire, ponctuation, répétitions maladroites, transitions abruptes. "
            . "Ne change NI le fond, NI la structure, NI la longueur, NI la syntaxe des encadrés (:::type … :::) et tableaux.\n\n"
            . $blob
            . "Réponds UNIQUEMENT en JSON : {\"sections\":[{\"num\":1,\"content\":\"texte corrigé\"}, …]} — une entrée par section, texte complet.";
        $data = Gemini::json($prompt, [
            'model' => 'pro', 'temperature' => 0.2, 'timeout' => 90, 'retries' => 0,
            'system' => 'Tu es correcteur professionnel francophone. Tu renvoies du JSON strict.',
        ]);
        $byNum = [];
        foreach ($sections as $section) {
            $byNum[(int) $section['num']] = $section;
        }
        $corrected = 0;
        foreach ((array) ($data['sections'] ?? []) as $fix) {
            $num = (int) ($fix['num'] ?? 0);
            $content = trim((string) ($fix['content'] ?? ''));
            if (!isset($byNum[$num]) || Util::wordCount($content) < 40) {
                continue;
            }
            // Garde-fou : une correction ne réduit jamais le texte de plus de 25 %
            $before = Util::wordCount((string) $byNum[$num]['content']);
            if ($before > 0 && Util::wordCount($content) < $before * 0.75) {
                continue;
            }
            Db::run('UPDATE sections SET content = ?, words = ?, updated_at = ? WHERE id = ?',
                [$content, Util::wordCount($content), Db::now(), (int) $byNum[$num]['id']]);
            $corrected++;
        }
        Util::journal((int) $project['id'], 'ok', 'Relecture « ' . $chapter['title'] . ' » : ' . $corrected . ' section(s) corrigée(s)');
        return ['corrected' => $corrected, 'label' => $chapter['title']];
    }

    // ── Interne ────────────────────────────────────────────────────────────

    private static function writeSection(array $project, ?array $concept, array $section): string
    {
        // MODE TRADUCTION : le projet est la version étrangère d'un livre déjà
        // écrit — chaque section est TRADUITE depuis la source (mêmes numéros),
        // avec les mêmes points de contrôle et la même reprise sur erreur.
        if (!empty($project['translate_from'])) {
            return self::translateSection($project, $section);
        }
        return self::writeSectionOriginal($project, $concept, $section);
    }

    /** Traduit la section homologue du projet source (même chapitre, même numéro). */
    private static function translateSection(array $project, array $section): string
    {
        $lang = (string) ($project['translate_lang'] ?? 'anglais');
        $source = Db::one(
            "SELECT s.content FROM sections s JOIN chapters c ON c.id = s.chapter_id
             WHERE c.project_id = ? AND c.num = ? AND s.num = ? AND s.content IS NOT NULL",
            [(int) $project['translate_from'], (int) $section['chapter_num'], (int) $section['num']]
        );
        if (!$source || trim((string) $source['content']) === '') {
            throw new \RuntimeException('Section source introuvable — le livre d\'origine doit être entièrement rédigé.');
        }
        $prompt = "TRADUIS en {$lang} la section suivante d'un livre pratique, pour des lecteurs natifs.\n"
            . "Règles :\n- traduction naturelle et idiomatique (pas littérale), même ton, même structure ;\n"
            . "- adapte les expressions, unités et références culturelles au lectorat {$lang} ;\n"
            . "- conserve EXACTEMENT la syntaxe des encadrés (:::type … :::) et des tableaux (:::tableau, lignes « a | b ») en traduisant leur contenu ;\n"
            . "- listes avec « – » conservées.\n\n"
            . "TEXTE SOURCE (français) :\n---\n" . trim((string) $source['content']) . "\n---\n\n"
            . "Réponds UNIQUEMENT avec la traduction, sans commentaire.";
        $text = trim(Gemini::text($prompt, [
            'model' => 'pro', 'temperature' => 0.5, 'timeout' => 120, 'retries' => 1,
            'system' => "Tu es traducteur éditorial professionnel vers le {$lang}.",
        ]));
        if (Util::wordCount($text) < 60) {
            throw new \RuntimeException('Traduction trop courte — nouvel essai au prochain passage.');
        }
        return $text;
    }

    private static function writeSectionOriginal(array $project, ?array $concept, array $section): string
    {
        $projectId = (int) $project['id'];
        $sectionsPer = max(1, (int) Config::get('writing.sections_per_chapter', 3));
        $targetWords = max(250, (int) round($section['target_words'] / $sectionsPer));

        $toc = Toc::draft($project);
        $tocText = '';
        foreach ($toc as $i => $chapter) {
            $tocText .= ($i + 1) . '. ' . $chapter['title'] . "\n";
        }

        $siblings = Db::all('SELECT num, title FROM sections WHERE chapter_id = ? ORDER BY num', [$section['chap_id']]);
        $siblingText = implode(' · ', array_map(fn ($s) => $s['title'], $siblings));

        // Continuité : fin du dernier texte validé
        $tailChars = (int) Config::get('writing.context_tail_chars', 700);
        $previous = Db::one(
            "SELECT s.content FROM sections s JOIN chapters c ON c.id = s.chapter_id
             WHERE c.project_id = ? AND s.status = 'done' AND s.content IS NOT NULL
             ORDER BY c.num DESC, s.num DESC LIMIT 1",
            [$projectId]
        );
        $tail = $previous ? trim(mb_substr((string) $previous['content'], -$tailChars)) : '';

        // LANGUE DU LIVRE : un livre déclaré anglais s'écrit en anglais.
        $langCode = Lang::codeOf($project);
        $langName = Lang::promptName($langCode);

        $bookTitle = $concept['title'] ?? $project['title'];
        $hook = $concept['hook'] ?? '';
        $promise = $concept['description'] ?? '';
        $role = (string) ($section['chapter_role'] ?? 'chapter');

        $calloutRule = "- enrichis le texte avec 1 à 2 ENCADRÉS à forte valeur ajoutée, insérés aux endroits pertinents, "
            . "choisis parmi ces types (varie-les, jamais deux fois le même type dans une section) :\n"
            . "  :::retenir (l'essentiel en 2-3 phrases) · :::chiffre (un chiffre marquant et son explication) · "
            . ":::conseil (astuce immédiatement actionnable) · :::exemple (mini-cas concret) · "
            . ":::faq (une question que se pose le lecteur, suivie de la réponse) · :::attention (piège à éviter)\n"
            . "  SYNTAXE EXACTE, seule mise en forme autorisée :\n  :::conseil\n  Texte de l'encadré…\n  :::\n"
            . "- quand un comparatif, des dosages ou un planning s'y prêtent, utilise un TABLEAU (max 1 par section, 2-4 colonnes) :\n"
            . "  :::tableau\n  En-tête A | En-tête B | En-tête C\n  valeur | valeur | valeur\n  :::\n";

        $roleBrief = match ($role) {
            'intro' => "Tu rédiges l'INTRODUCTION du livre. Objectifs : accrocher dès la première phrase par une "
                . "situation que le lecteur vit, poser le problème, formuler la promesse du livre et annoncer le "
                . "parcours. Pas de conseils détaillés ici (ils viennent dans les chapitres). Encadrés : 0 à 1 maximum "
                . "(plutôt :::retenir en fin de section).\n",
            'conclusion' => "Tu rédiges la CONCLUSION du livre. Objectifs : synthétiser les transformations promises, "
                . "renvoyer aux moments clés du livre, puis donner un élan final. Dans la section « plan d'action », "
                . "inclus une liste « – » d'actions concrètes à démarrer cette semaine. Encadré :::retenir bienvenu.\n",
            default => '',
        };

        $chapterLine = $role === 'chapter'
            ? "CHAPITRE EN COURS : « {$section['chapter_title']} »\n"
            : mb_strtoupper($section['chapter_title']) . " du livre\n";

        $prompt = "LIVRE : « {$bookTitle} »" . ($hook ? " — {$hook}" : '') . "\n"
            . ($promise ? "PROMESSE : {$promise}\n" : '')
            . "SOMMAIRE COMPLET (entre l'introduction et la conclusion) :\n{$tocText}"
            . $chapterLine
            . "SES SOUS-PARTIES : {$siblingText}\n"
            . "SECTION À RÉDIGER MAINTENANT : §{$section['num']} « {$section['title']} »\n"
            . ($tail !== '' ? "FIN DU TEXTE DÉJÀ ÉCRIT (pour la continuité, ne pas répéter) :\n« …{$tail} »\n" : '')
            . "\n" . $roleBrief
            . "Rédige intégralement cette section en {$langName}, pour des lecteurs natifs.\n"
            . "Contraintes :\n"
            . "- environ {$targetWords} mots (±15 %) ;\n"
            . "- ton : {$project['tone']}"
            . ($langCode === 'fr' ? ", tutoiement interdit, s'adresser au lecteur avec « vous »" : ', vouvoiement de politesse si la langue le prévoit')
            . " ;\n"
            . "- paragraphes de 3 à 6 phrases séparés par une ligne vide ; listes à puces « – » autorisées avec parcimonie ;\n"
            . $calloutRule
            . "- en dehors des encadrés ci-dessus : AUCUN titre, AUCUN markdown (pas de #, pas de **), aucune numérotation ;\n"
            . "- ne conclus pas le livre" . ($role === 'conclusion' ? " avant la dernière section" : '') . ", ne résume pas la section : enchaîne naturellement ;\n"
            . "- contenu concret : exemples, chiffres plausibles, mises en situation, pas de généralités creuses.";

        return trim(Gemini::text($prompt, [
            'model'       => 'pro',
            'temperature' => (float) Config::get('gemini.temperature_writing', 0.8),
            'system'      => "Tu es un auteur professionnel de livres pratiques, tu écris exclusivement en {$langName}. "
                . "Tu écris un texte fluide, précis, sans remplissage, prêt à être imprimé.",
        ]));
    }

    /** Libellé lisible d'un chapitre (Introduction / Chapitre N / Conclusion). */
    private static function labelFor(int $projectId, string $role, int $num): string
    {
        $labels = Lang::labels(Lang::codeOf(Db::one('SELECT * FROM projects WHERE id = ?', [$projectId]) ?: []));
        if ($role === 'intro') {
            return $labels['intro'];
        }
        if ($role === 'conclusion') {
            return $labels['conclusion'];
        }
        $row = Db::one(
            "SELECT COUNT(*) AS n FROM chapters WHERE project_id = ? AND role = 'chapter' AND num < ?",
            [$projectId, $num]
        );
        return $labels['chapter'] . ' ' . ((int) ($row['n'] ?? 0) + 1);
    }

    private static function duplicateParagraphs(int $chapterId): int
    {
        $rows = Db::all("SELECT content FROM sections WHERE chapter_id = ? AND content IS NOT NULL", [$chapterId]);
        $seen = [];
        $duplicates = 0;
        foreach ($rows as $row) {
            foreach (Util::paragraphs((string) $row['content']) as $paragraph) {
                $key = md5(mb_strtolower(preg_replace('/\W+/u', '', $paragraph) ?? ''));
                if (isset($seen[$key])) {
                    $duplicates++;
                } else {
                    $seen[$key] = true;
                }
            }
        }
        return $duplicates;
    }

    private static function freshProject(int $projectId): array
    {
        return Db::one('SELECT * FROM projects WHERE id = ?', [$projectId]) ?? [];
    }
}
