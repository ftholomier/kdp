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
            "SELECT s.*, c.num AS chapter_num, c.title AS chapter_title, c.target_words, c.id AS chap_id
             FROM sections s JOIN chapters c ON c.id = s.chapter_id
             WHERE c.project_id = ? AND s.status != 'done'
             ORDER BY c.num, s.num LIMIT 1",
            [$projectId]
        );

        if (!$section) {
            Db::run("UPDATE projects SET writing_status = 'done', step = GREATEST(step, 6), updated_at = ? WHERE id = ?", [Db::now(), $projectId]);
            Util::journal($projectId, 'ok', 'Rédaction terminée · manuscrit complet enregistré');
            return self::status(self::freshProject($projectId));
        }

        $checkpoint = 'ch' . $section['chapter_num'] . ' §' . $section['num'];
        Db::run("UPDATE sections SET status = 'writing' WHERE id = ?", [$section['id']]);
        Db::run("UPDATE chapters SET status = 'writing' WHERE id = ? AND status = 'wait'", [$section['chap_id']]);
        Util::journal($projectId, 'ok', 'Chapitre ' . $section['chapter_num'] . ' · section ' . $section['num'] . ' — rédaction');

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
        Util::journal($projectId, 'ok', 'Chapitre ' . $section['chapter_num'] . ' · section ' . $section['num'] . ' — terminée (' . Util::nf($words) . ' mots)');

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
            Util::journal($projectId, 'ok', 'Chapitre ' . $section['chapter_num'] . ' — terminé · sauvegarde');
        }

        return self::status(self::freshProject($projectId));
    }

    /** État complet de la rédaction (progression, chapitres, checkpoint). */
    public static function status(array $project): array
    {
        $projectId = (int) $project['id'];
        $chapters = Db::all(
            "SELECT c.id, c.num, c.title, c.status, c.target_words,
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

        $secondsPer = (int) Config::get('writing.seconds_per_section', 40);
        $chaptersOut = array_map(fn ($c) => [
            'num'           => (int) $c['num'],
            'title'         => $c['title'],
            'status'        => $c['status'],
            'words_done'    => (int) $c['words_done'],
            'words_target'  => (int) $c['target_words'],
            'pct'           => (int) $c['sections_total'] > 0 ? (int) round((int) $c['sections_done'] / (int) $c['sections_total'] * 100) : 0,
        ], $chapters);

        return [
            'writing_status' => $project['writing_status'],
            'progress'       => $progress,
            'words_done'     => $wordsDone,
            'chapter_current'=> $current ? (int) $current['num'] : count($chapters),
            'chapter_total'  => count($chapters),
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

    // ── Interne ────────────────────────────────────────────────────────────

    private static function writeSection(array $project, ?array $concept, array $section): string
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

        $bookTitle = $concept['title'] ?? $project['title'];
        $hook = $concept['hook'] ?? '';
        $promise = $concept['description'] ?? '';

        $prompt = "LIVRE : « {$bookTitle} »" . ($hook ? " — {$hook}" : '') . "\n"
            . ($promise ? "PROMESSE : {$promise}\n" : '')
            . "SOMMAIRE COMPLET :\n{$tocText}"
            . "CHAPITRE EN COURS : {$section['chapter_num']}. {$section['chapter_title']}\n"
            . "SES SOUS-PARTIES : {$siblingText}\n"
            . "SECTION À RÉDIGER MAINTENANT : §{$section['num']} « {$section['title']} »\n"
            . ($tail !== '' ? "FIN DU TEXTE DÉJÀ ÉCRIT (pour la continuité, ne pas répéter) :\n« …{$tail} »\n" : '')
            . "\nRédige intégralement cette section en français.\n"
            . "Contraintes :\n"
            . "- environ {$targetWords} mots (±15 %) ;\n"
            . "- ton : {$project['tone']}, tutoiement interdit, s'adresser au lecteur avec « vous » ;\n"
            . "- paragraphes de 3 à 6 phrases séparés par une ligne vide ; listes à puces « – » autorisées avec parcimonie ;\n"
            . "- AUCUN titre, AUCUN markdown (pas de #, pas de **), aucune numérotation : uniquement le corps du texte ;\n"
            . "- ne conclus pas le livre, ne résume pas la section : enchaîne naturellement avec la suite ;\n"
            . "- contenu concret : exemples, chiffres plausibles, mises en situation, pas de généralités creuses.";

        return trim(Gemini::text($prompt, [
            'model'       => 'pro',
            'temperature' => (float) Config::get('gemini.temperature_writing', 0.8),
            'system'      => "Tu es un auteur professionnel de livres pratiques en français. "
                . "Tu écris un texte fluide, précis, sans remplissage, prêt à être imprimé.",
        ]));
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
