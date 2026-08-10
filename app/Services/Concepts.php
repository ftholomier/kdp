<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Db;

/**
 * Étape 2 — Concept : 8 livres à écrire pour le thème retenu,
 * calés sur ce qui se vend le mieux sur Amazon.fr.
 */
final class Concepts
{
    public static function generate(array $project, array $theme): array
    {
        $idea = trim((string) ($project['idea'] ?? ''));
        $ideaLine = $idea !== '' ? "Idée d'origine de l'auteur : « {$idea} »\n" : '';

        // Connecteur Canopy : le top réel de la niche alimente la détection d'angles morts
        $realData = '';
        $grounded = false;
        if (Canopy::enabled()) {
            try {
                $snapshot = Canopy::marketSnapshot($theme['name']);
                if ($snapshot) {
                    $realData = "TOP RÉSULTATS RÉELS AMAZON POUR CETTE NICHE (via API — analyse ces titres "
                        . "existants pour repérer les angles morts, les prix pratiqués et le niveau de "
                        . "concurrence réel) :\n" . Canopy::formatSnapshot($snapshot) . "\n";
                    $grounded = true;
                }
            } catch (\Throwable $e) {
                error_log('[canopy] concepts : ' . $e->getMessage());
            }
        }

        $prompt = "Tu es directeur éditorial spécialisé en autoédition Amazon KDP France.\n"
            . "Thématique retenue : « {$theme['name']} » ({$theme['category']}).\n"
            . $ideaLine
            . $realData
            . "Analyse ce qui se vend le mieux dans cette niche sur Amazon.fr (top 100, avis négatifs, "
            . "angles morts, prix) et propose 8 LIVRES À ÉCRIRE, chacun comblant un angle mort réel des "
            . "meilleures ventes" . ($grounded ? " — en particulier des titres réels listés ci-dessus" : '')
            . ". Titres accrocheurs en français, commercialement solides.\n\n"
            . "Réponds UNIQUEMENT avec un objet JSON valide :\n"
            . '{"books":[{"title":"...","short_title":"...","hook":"...","description":"...","badge":"...",'
            . '"competition":"...","price":"14,90 €","pages_est":184}]}' . "\n"
            . "Contraintes :\n"
            . "- exactement 8 éléments ;\n"
            . "- \"title\" : titre du livre (max 60 caractères) ;\n"
            . "- \"short_title\" : le même titre, éventuellement raccourci pour une mini-couverture ;\n"
            . "- \"hook\" : accroche d'une phrase, percutante (max 90 caractères) ;\n"
            . "- \"description\" : 2 phrases max décrivant promesse et contenu (max 260 caractères) ;\n"
            . "- \"badge\" : l'argument marché en 2-3 mots, ex. \"Angle vacant\", \"Fort volume\", "
            . "\"Aucun concurrent FR\", \"Marge élevée\", \"Tendance +38 %\", \"Niche précise\" ;\n"
            . "- \"competition\" : Très faible | Faible | Moyenne | Forte ;\n"
            . "- \"price\" : prix broché conseillé \"12,90 €\" ;\n"
            . "- \"pages_est\" : entier 100-260.";

        $data = Gemini::json($prompt, [
            'model'       => 'fast',
            'temperature' => (float) Config::get('gemini.temperature_ideas', 0.9),
            'system'      => "Tu produis des concepts éditoriaux vendeurs et réalistes pour Amazon.fr. Réponse en français.",
        ]);

        $books = $data['books'] ?? null;
        if (!is_array($books) || count($books) < 4) {
            throw new \RuntimeException("La génération n'a pas produit assez de concepts, relancez.");
        }

        $projectId = (int) $project['id'];
        Db::run('DELETE FROM concepts WHERE project_id = ?', [$projectId]);
        $position = 0;
        foreach (array_slice(array_values($books), 0, 8) as $book) {
            Db::run(
                'INSERT INTO concepts (project_id, position, title, short_title, hook, description, badge, competition, price, pages_est)
                 VALUES (?,?,?,?,?,?,?,?,?,?)',
                [
                    $projectId,
                    $position++,
                    mb_substr(trim((string) ($book['title'] ?? 'Sans titre')), 0, 250),
                    mb_substr(trim((string) ($book['short_title'] ?? $book['title'] ?? '')), 0, 120),
                    mb_substr(trim((string) ($book['hook'] ?? '')), 0, 250),
                    mb_substr(trim((string) ($book['description'] ?? '')), 0, 600),
                    mb_substr(trim((string) ($book['badge'] ?? '')), 0, 60),
                    mb_substr(trim((string) ($book['competition'] ?? 'Moyenne')), 0, 30),
                    mb_substr(trim((string) ($book['price'] ?? '')), 0, 20),
                    max(60, min(400, (int) ($book['pages_est'] ?? 180))),
                ]
            );
        }
        return ['books' => self::listFor($projectId), 'grounded' => $grounded];
    }

    public static function listFor(int $projectId): array
    {
        return Db::all('SELECT * FROM concepts WHERE project_id = ? ORDER BY position', [$projectId]);
    }
}
