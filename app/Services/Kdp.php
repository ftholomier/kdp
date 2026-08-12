<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Db;

/**
 * Publication Amazon KDP : métadonnées du formulaire (titre, description,
 * mots-clés, catégories, prix) et payload JSON consommé par le userscript
 * de remplissage automatique (tools/kdp-autofill.user.js).
 */
final class Kdp
{
    public static function meta(int $projectId): array
    {
        $meta = Db::one('SELECT * FROM kdp_meta WHERE project_id = ?', [$projectId]);
        if (!$meta) {
            return [
                'project_id' => $projectId, 'subtitle' => '', 'author_first' => '', 'author_last' => '',
                'description_html' => '', 'keywords' => [], 'categories' => [], 'price' => null, 'isbn' => '',
            ];
        }
        $meta['keywords'] = json_decode((string) $meta['keywords'], true) ?: [];
        $meta['categories'] = json_decode((string) $meta['categories'], true) ?: [];
        $meta['price'] = $meta['price'] !== null ? (float) $meta['price'] : null;
        return $meta;
    }

    public static function save(int $projectId, array $input): array
    {
        $keywords = array_slice(array_values(array_filter(array_map(
            fn ($k) => mb_substr(trim((string) $k), 0, 50),
            (array) ($input['keywords'] ?? [])
        ), fn ($k) => $k !== '')), 0, 7);
        $categories = array_slice(array_values(array_filter(array_map(
            fn ($c) => mb_substr(trim((string) $c), 0, 200),
            (array) ($input['categories'] ?? [])
        ), fn ($c) => $c !== '')), 0, 3);

        $price = $input['price'] ?? null;
        $price = ($price === null || $price === '') ? null : Layout::parsePrice((string) $price);

        Db::run(
            'INSERT INTO kdp_meta (project_id, subtitle, author_first, author_last, description_html, keywords, categories, price, isbn, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE subtitle = VALUES(subtitle), author_first = VALUES(author_first),
               author_last = VALUES(author_last), description_html = VALUES(description_html),
               keywords = VALUES(keywords), categories = VALUES(categories), price = VALUES(price),
               isbn = VALUES(isbn), updated_at = VALUES(updated_at)',
            [
                $projectId,
                mb_substr(trim((string) ($input['subtitle'] ?? '')), 0, 250),
                mb_substr(trim((string) ($input['author_first'] ?? '')), 0, 100),
                mb_substr(trim((string) ($input['author_last'] ?? '')), 0, 100),
                (string) ($input['description_html'] ?? ''),
                json_encode($keywords, JSON_UNESCAPED_UNICODE),
                json_encode($categories, JSON_UNESCAPED_UNICODE),
                $price,
                mb_substr(trim((string) ($input['isbn'] ?? '')), 0, 20),
                Db::now(),
            ]
        );
        return self::meta($projectId);
    }

    /** Génère les métadonnées vendeuses via Gemini. */
    public static function generate(array $project, array $concept, array $user): array
    {
        $prompt = "Tu es expert du référencement Amazon KDP France. Prépare les métadonnées de publication "
            . "d'un livre broché français.\n"
            . "Titre : « {$concept['title']} »\nAccroche : « {$concept['hook']} »\n"
            . "Promesse : {$concept['description']}\nPrix envisagé : {$concept['price']}.\n\n"
            . "Réponds UNIQUEMENT avec un objet JSON valide :\n"
            . '{"subtitle":"...","description_html":"...","keywords":["k1","k2","k3","k4","k5","k6","k7"],'
            . '"categories":["...","...","..."],"price":14.90}' . "\n"
            . "Contraintes :\n"
            . "- \"subtitle\" : sous-titre riche en mots-clés (max 120 caractères) ;\n"
            . "- \"description_html\" : description Amazon de 120-180 mots, HTML limité à <p>, <b>, <i>, <ul>, <li>, <br> ; "
            . "ouvre sur le problème du lecteur, promesse claire, 3-5 puces de bénéfices, appel à l'action final ;\n"
            . "- \"keywords\" : exactement 7 expressions de recherche Amazon.fr (2-4 mots, sans répéter le titre) ;\n"
            . "- \"categories\" : 3 chemins de catégories KDP plausibles, format \"Rayon > Sous-rayon > Niche\" ;\n"
            . "- \"price\" : prix broché en euros (nombre).";

        $data = Gemini::json($prompt, [
            'model' => 'fast', 'temperature' => 0.8, 'search' => false,
            'system' => 'Tu optimises des fiches produit Amazon KDP en français.',
        ]);

        $name = trim((string) ($user['display_name'] ?? ''));
        $parts = $name !== '' ? explode(' ', $name, 2) : ['', ''];

        return self::save((int) $project['id'], [
            'subtitle'         => $data['subtitle'] ?? '',
            'author_first'     => $parts[0] ?? '',
            'author_last'      => $parts[1] ?? '',
            'description_html' => self::sanitizeHtml((string) ($data['description_html'] ?? '')),
            'keywords'         => $data['keywords'] ?? [],
            'categories'       => $data['categories'] ?? [],
            'price'            => $data['price'] ?? null,
        ]);
    }

    /** Payload JSON complet pour le userscript de remplissage du formulaire KDP. */
    public static function payload(array $project, ?array $concept): array
    {
        $projectId = (int) $project['id'];
        $meta = self::meta($projectId);
        $cover = Db::one('SELECT texts FROM covers WHERE project_id = ?', [$projectId]);
        $texts = $cover ? (json_decode((string) $cover['texts'], true) ?: []) : [];
        $summary = Layout::summary($project, $concept);

        return [
            'project_id'  => $projectId,
            'language'    => Config::get('kdp.language', 'Français'),
            'marketplace' => Config::get('kdp.marketplace', 'Amazon.fr'),
            'title'       => $texts['title'] ?? ($concept['title'] ?? $project['title']),
            'subtitle'    => $meta['subtitle'],
            'author'      => [
                'first_name' => $meta['author_first'] ?: (explode(' ', (string) ($texts['author'] ?? ''), 2)[0] ?? ''),
                'last_name'  => $meta['author_last'] ?: (explode(' ', (string) ($texts['author'] ?? ''), 2)[1] ?? ''),
            ],
            'description_html' => $meta['description_html'],
            'keywords'    => array_pad($meta['keywords'], 7, ''),
            'categories'  => $meta['categories'],
            'price_eur'   => $meta['price'] ?? $summary['pricing']['price'],
            'isbn'        => $meta['isbn'],
            'trim'        => $project['trim_format'],
            'pages'       => $summary['geometry']['pages'],
            'ink'         => ($project['photo_style'] ?? 'nb') === 'couleur' ? 'color' : 'black_white',
            'paper'       => 'white',
            // L'intérieur est exporté AVEC fond perdu (aplats bord à bord) :
            // choisir « avec fond perdu » dans le formulaire KDP.
            'bleed'       => true,
            'generated_at'=> date('c'),
        ];
    }

    // ── Jetons d'API du userscript ─────────────────────────────────────────

    public static function tokens(int $userId): array
    {
        return Db::all(
            'SELECT id, token, label, created_at, last_used_at FROM api_tokens WHERE user_id = ? ORDER BY id DESC',
            [$userId]
        );
    }

    public static function createToken(int $userId, string $label): array
    {
        $token = bin2hex(random_bytes(32));
        Db::run(
            'INSERT INTO api_tokens (user_id, token, label, created_at) VALUES (?,?,?,?)',
            [$userId, $token, mb_substr($label ?: 'Userscript KDP', 0, 100), Db::now()]
        );
        return ['token' => $token];
    }

    public static function revokeToken(int $userId, int $tokenId): void
    {
        Db::run('DELETE FROM api_tokens WHERE id = ? AND user_id = ?', [$tokenId, $userId]);
    }

    private static function sanitizeHtml(string $html): string
    {
        return strip_tags($html, '<p><b><strong><i><em><ul><ol><li><br><h4><h5><h6>');
    }
}
