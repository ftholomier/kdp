<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Db;

/**
 * Étape 1 — Niche : analyse d'une idée ou tendances des catégories Amazon.
 * Données produites par Gemini avec ancrage Google Search : ce sont des
 * estimations éditoriales argumentées, pas des chiffres Amazon certifiés.
 */
final class Market
{
    private const JSON_SPEC = <<<SPEC
Réponds UNIQUEMENT avec un objet JSON valide, sans texte autour, de la forme :
{"themes":[{"name":"...","category":"...","score":"...","why":"...","demand":85,"competition":"...","price_median":"13,90 €"}]}
Contraintes :
- exactement 6 éléments dans "themes" ;
- "category" au format "Rayon › Sous-rayon" (rayons Amazon.fr) ;
- "why" : 1 à 2 phrases concrètes (max 220 caractères) justifiant le potentiel, avec un fait ou une tendance ;
- "demand" : entier 0-100 ;
- "competition" : "Très faible", "Faible", "Moyenne", "Forte" ou "Très forte" ;
- "price_median" : prix broché plausible au format "12,90 €".
SPEC;

    public static function analyze(array $project, string $idea): array
    {
        $prompt = "Tu es analyste du marché de l'autoédition Amazon KDP France (Amazon.fr). "
            . "Un auteur décrit son idée de livre :\n\n« " . trim($idea) . " »\n\n"
            . "En t'appuyant sur les tendances de vente actuelles d'Amazon.fr (best-sellers, "
            . "volumes de recherche, nouveautés, avis négatifs des livres existants), propose les "
            . "6 thématiques de livre les PLUS VENDEUSES qui confrontent cette idée au marché : "
            . "des angles précis, différenciants, réalistes pour un auteur indépendant.\n"
            . "\"score\" : note de potentiel commercial parmi A+, A, B+, B, C — classe du meilleur au moins bon.\n\n"
            . self::JSON_SPEC;

        $data = Gemini::json($prompt, [
            'model'       => 'fast',
            'temperature' => (float) Config::get('gemini.temperature_ideas', 0.9),
            'system'      => "Tu produis des analyses marché fiables et actuelles pour Amazon.fr. Réponse en français.",
        ]);

        return self::store((int) $project['id'], 'analysis', $data);
    }

    public static function trends(array $project): array
    {
        $prompt = "Tu es analyste du marché de l'autoédition Amazon KDP France (Amazon.fr). "
            . "Liste les 6 thématiques/catégories de livres LES PLUS CONSULTÉES ET LES PLUS VENDEUSES "
            . "sur Amazon.fr ces 30 derniers jours pour un auteur indépendant KDP (broché). "
            . "Appuie-toi sur les tendances réelles et récentes : classements, saisonnalité, actualité. "
            . "Classe de la plus consultée à la moins consultée.\n"
            . "\"score\" : rang au format \"#1\" à \"#6\".\n"
            . "\"category\" : ici une courte mention du signal, ex. \"Le plus consulté cette semaine\", "
            . "\"Top catégorie 30 jours\", \"Tendance montante\".\n\n"
            . self::JSON_SPEC;

        $data = Gemini::json($prompt, [
            'model'       => 'fast',
            'temperature' => (float) Config::get('gemini.temperature_ideas', 0.9),
            'system'      => "Tu produis des analyses marché fiables et actuelles pour Amazon.fr. Réponse en français.",
        ]);

        return self::store((int) $project['id'], 'trends', $data);
    }

    public static function listFor(int $projectId, string $source): array
    {
        return Db::all(
            'SELECT * FROM themes WHERE project_id = ? AND source = ? ORDER BY position',
            [$projectId, $source]
        );
    }

    private static function store(int $projectId, string $source, array $data): array
    {
        $themes = $data['themes'] ?? null;
        if (!is_array($themes) || count($themes) < 3) {
            throw new \RuntimeException("L'analyse n'a pas produit assez de thématiques, relancez.");
        }
        Db::run('DELETE FROM themes WHERE project_id = ? AND source = ?', [$projectId, $source]);
        $position = 0;
        foreach (array_slice(array_values($themes), 0, 6) as $theme) {
            Db::run(
                'INSERT INTO themes (project_id, source, position, name, category, score, why, demand, competition, price_median)
                 VALUES (?,?,?,?,?,?,?,?,?,?)',
                [
                    $projectId,
                    $source,
                    $position++,
                    mb_substr(trim((string) ($theme['name'] ?? 'Thème')), 0, 180),
                    mb_substr(trim((string) ($theme['category'] ?? '')), 0, 180),
                    mb_substr(trim((string) ($theme['score'] ?? '')), 0, 8),
                    mb_substr(trim((string) ($theme['why'] ?? '')), 0, 500),
                    max(0, min(100, (int) ($theme['demand'] ?? 50))),
                    mb_substr(trim((string) ($theme['competition'] ?? 'Moyenne')), 0, 30),
                    mb_substr(trim((string) ($theme['price_median'] ?? '')), 0, 20),
                ]
            );
        }
        return self::listFor($projectId, $source);
    }
}
