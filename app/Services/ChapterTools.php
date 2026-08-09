<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Db;
use App\Core\Util;

/**
 * Étape 6 — Relecture : lecture des chapitres, actions de retouche IA
 * et contrôle qualité calculé sur le texte réel.
 */
final class ChapterTools
{
    public static function chapter(int $projectId, int $num): array
    {
        $chapter = Db::one('SELECT * FROM chapters WHERE project_id = ? AND num = ?', [$projectId, $num]);
        if (!$chapter) {
            throw new \RuntimeException('Chapitre introuvable.');
        }
        $sections = Db::all('SELECT num, title, status, content, words FROM sections WHERE chapter_id = ? ORDER BY num', [$chapter['id']]);
        $images = Db::all('SELECT slot, caption, spec, filename, id FROM images WHERE project_id = ? AND chapter_num = ? ORDER BY slot', [$projectId, $num]);
        return ['chapter' => $chapter, 'sections' => $sections, 'images' => $images, 'quality' => self::quality($chapter, $sections)];
    }

    /**
     * Actions : rewrite (autre ton), extend (+400 mots), tighten (resserrer),
     * exercise (ajouter un exercice), factcheck (vérification factuelle).
     */
    public static function action(array $project, int $num, string $action, string $param = ''): array
    {
        $projectId = (int) $project['id'];
        $data = self::chapter($projectId, $num);
        $chapter = $data['chapter'];

        if ($action === 'factcheck') {
            $full = self::fullText($data['sections']);
            $notes = Gemini::text(
                "Voici un chapitre de livre pratique en français. Relève les affirmations factuelles "
                . "(chiffres, études, faits) qui mériteraient vérification ou nuance avant publication. "
                . "Réponds par une liste concise « – … » (max 8 points, en français). S'il n'y a rien de "
                . "douteux, réponds « Aucune affirmation à risque détectée. »\n\n" . $full,
                ['model' => 'pro', 'temperature' => 0.3]
            );
            Util::journal($projectId, 'dim', 'Vérification factuelle du chapitre ' . $num . ' effectuée');
            return ['notes' => trim($notes)];
        }

        $instruction = match ($action) {
            'rewrite' => "Réécris ce texte avec un ton « " . ($param ?: 'plus chaleureux') . " », en conservant "
                . "exactement les mêmes idées, la même structure et une longueur équivalente.",
            'extend'  => "Enrichis ce texte d'environ 400 mots supplémentaires : exemples concrets, mises en "
                . "situation, précisions utiles. Conserve le ton et la structure, insère les ajouts aux bons endroits.",
            'tighten' => "Resserre ce texte : supprime les redites, les généralités creuses et les tournures "
                . "verbeuses. Vise 15 à 20 % plus court sans perdre une seule idée.",
            'exercise'=> "Ajoute à la fin de ce texte un exercice pratique guidé (150-250 mots) directement "
                . "actionnable par le lecteur, introduit naturellement, sans titre ni markdown.",
            default   => throw new \RuntimeException('Action inconnue.'),
        };

        $sectionsOut = [];
        foreach ($data['sections'] as $section) {
            if (trim((string) $section['content']) === '') {
                continue;
            }
            $isLast = $section === end($data['sections']);
            if ($action === 'exercise' && !$isLast) {
                continue; // l'exercice s'ajoute uniquement en fin de chapitre
            }
            $text = Gemini::text(
                $instruction . "\nContraintes : français, vouvoiement, paragraphes séparés par une ligne vide, "
                . "aucun titre, aucun markdown.\n\nTEXTE :\n" . $section['content'],
                ['model' => 'pro', 'temperature' => (float) Config::get('gemini.temperature_writing', 0.8),
                 'system' => 'Tu es un éditeur littéraire exigeant. Tu retravailles le texte demandé, rien d’autre.']
            );
            $text = trim($text);
            Db::run(
                "UPDATE sections SET content = ?, words = ?, updated_at = ? WHERE chapter_id = ? AND num = ?",
                [$text, Util::wordCount($text), Db::now(), $chapter['id'], $section['num']]
            );
            $sectionsOut[] = (int) $section['num'];
        }

        $labels = ['rewrite' => 'Réécriture (ton ' . ($param ?: 'chaleureux') . ')', 'extend' => 'Allongé de ~400 mots',
                   'tighten' => 'Resserré', 'exercise' => 'Exercice pratique ajouté'];
        Util::journal($projectId, 'ok', 'Chapitre ' . $num . ' — ' . ($labels[$action] ?? $action));

        return self::chapter($projectId, $num);
    }

    /** Contrôle qualité honnête, calculé sur le texte. */
    public static function quality(array $chapter, array $sections): array
    {
        $text = self::fullText($sections);
        $words = Util::wordCount($text);
        $sentences = max(1, preg_match_all('/[.!?…]+(\s|$)/u', $text));
        $avgSentence = $words / $sentences;

        $readability = $avgSentence < 18 ? 'Facile' : ($avgSentence < 26 ? 'Moyenne' : 'Dense');

        // Répétitions : suites de 8 mots identiques
        $tokens = preg_split('/\s+/u', mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]/u', '', $text) ?? '')) ?: [];
        $shingles = [];
        $repetitions = 0;
        for ($i = 0; $i + 8 <= count($tokens); $i++) {
            $key = implode(' ', array_slice($tokens, $i, 8));
            if (isset($shingles[$key])) {
                $repetitions++;
                $i += 7;
            } else {
                $shingles[$key] = true;
            }
        }

        $target = (int) $chapter['target_words'];
        $delta = $target > 0 ? (int) round(($words - $target) / $target * 100) : 0;

        return [
            ['k' => 'Lisibilité',        'v' => $readability,                          'warn' => $readability === 'Dense'],
            ['k' => 'Répétitions',       'v' => $repetitions === 0 ? 'Aucune' : $repetitions . ' détectée' . ($repetitions > 1 ? 's' : ''), 'warn' => $repetitions > 0],
            ['k' => 'Longueur vs cible', 'v' => ($delta >= 0 ? '+' : '') . $delta . ' %', 'warn' => abs($delta) > 25],
            ['k' => 'Mots',              'v' => Util::nf($words),                      'warn' => false],
        ];
    }

    private static function fullText(array $sections): string
    {
        return implode("\n\n", array_filter(array_map(fn ($s) => trim((string) ($s['content'] ?? '')), $sections)));
    }
}
