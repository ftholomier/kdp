<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Settings;

/**
 * Connecteur Canopy API (https://www.canopyapi.co) — vraies données Amazon.
 *
 * Interroge l'endpoint GraphQL (recherche produits : titres, prix, notes,
 * nombre d'avis) pour ancrer l'analyse de niche (étape 1) et les concepts
 * (étape 2) sur les résultats réels d'Amazon.
 *
 * Pensé pour l'offre GRATUITE :
 *   - cache disque (TTL configurable, 7 jours par défaut) : une même
 *     recherche ne consomme qu'un seul crédit par semaine ;
 *   - garde-fou de quota mensuel (monthly_budget) : au-delà, le connecteur
 *     se met en veille et l'application retombe sur Gemini seul ;
 *   - toute erreur (clé absente, réseau, quota, schéma) est non bloquante.
 */
final class Canopy
{
    private const SEARCH_QUERY = <<<'GQL'
    query Search($searchTerm: String!, $domain: AmazonDomain!, $page: String!) {
      amazonProductSearchResults(input: { searchTerm: $searchTerm, domain: $domain }) {
        productResults(input: { page: $page }) {
          results {
            asin
            title
            url
            price { display value currency }
            rating
            ratingsTotal
          }
          pageInfo { currentPage totalPages }
        }
      }
    }
    GQL;

    public static function enabled(): bool
    {
        return trim((string) Settings::get('canopy.api_key', '')) !== '';
    }

    private static function domain(): string
    {
        return strtoupper((string) Settings::get('canopy.domain', 'FR'));
    }

    /** État du connecteur (affiché dans l'interface). */
    public static function status(): array
    {
        $usage = self::usage();
        $budget = (int) Config::get('canopy.monthly_budget', 95);
        return [
            'enabled'   => self::enabled(),
            'used'      => $usage,
            'budget'    => $budget,
            'exhausted' => self::enabled() && $usage >= $budget,
            'domain'    => self::domain(),
        ];
    }

    /**
     * Synthèse marché pour un mot-clé : top titres réels, prix médian,
     * note moyenne, volume d'avis. null si indisponible (repli Gemini seul).
     */
    public static function marketSnapshot(string $term, bool $force = false): ?array
    {
        $products = self::search($term, 1, $force);
        if (!$products) {
            return null;
        }

        $prices = [];
        $ratings = [];
        $reviews = 0;
        foreach ($products as $product) {
            if ($product['price'] !== null && $product['price'] > 0) {
                $prices[] = $product['price'];
            }
            if ($product['rating'] !== null) {
                $ratings[] = $product['rating'];
            }
            $reviews += (int) $product['ratings_total'];
        }
        sort($prices);
        $median = $prices ? $prices[(int) floor((count($prices) - 1) / 2)] : null;

        return [
            'term'         => $term,
            'count'        => count($products),
            'median_price' => $median,
            'avg_rating'   => $ratings ? round(array_sum($ratings) / count($ratings), 2) : null,
            'total_reviews'=> $reviews,
            'top'          => array_slice($products, 0, 10),
        ];
    }

    /** Texte compact injecté dans les prompts Gemini. */
    public static function formatSnapshot(array $snapshot): string
    {
        $lines = sprintf(
            "Recherche Amazon « %s » (page 1, données réelles) : %d résultats · prix médian %s · note moyenne %s · %s avis cumulés\n",
            $snapshot['term'],
            $snapshot['count'],
            $snapshot['median_price'] !== null ? number_format($snapshot['median_price'], 2, ',', ' ') . ' €' : 'n/c',
            $snapshot['avg_rating'] !== null ? $snapshot['avg_rating'] . '/5' : 'n/c',
            number_format((float) $snapshot['total_reviews'], 0, ',', ' ')
        );
        foreach ($snapshot['top'] as $i => $product) {
            $lines .= sprintf(
                "  %d. « %s » — %s — %s — %s avis\n",
                $i + 1,
                mb_substr($product['title'], 0, 90),
                $product['price'] !== null ? number_format($product['price'], 2, ',', ' ') . ' €' : 'prix n/c',
                $product['rating'] !== null ? $product['rating'] . '★' : 'note n/c',
                number_format((float) $product['ratings_total'], 0, ',', ' ')
            );
        }
        return $lines;
    }

    /**
     * Recherche produits (mise en cache).
     * @return array<int,array{asin:string,title:string,url:string,price:?float,rating:?float,ratings_total:int}>|null
     */
    public static function search(string $term, int $page = 1, bool $force = false): ?array
    {
        if (!self::enabled()) {
            return null;
        }
        $term = mb_substr(trim($term), 0, 120);
        if ($term === '') {
            return null;
        }
        $domain = self::domain();
        $cacheKey = 'search-' . md5($domain . '|' . mb_strtolower($term) . '|' . $page);

        if (!$force) {
            $cached = self::cacheGet($cacheKey);
            if ($cached !== null) {
                return $cached ?: null; // un tableau vide en cache = « aucun résultat »
            }
        }

        $status = self::status();
        if ($status['exhausted']) {
            error_log('[canopy] quota mensuel atteint (' . $status['used'] . '/' . $status['budget'] . ') — repli Gemini seul');
            return null;
        }

        try {
            $data = self::graphql(self::SEARCH_QUERY, [
                'searchTerm' => $term,
                'domain'     => $domain,
                'page'       => (string) $page,
            ]);
        } catch (\Throwable $e) {
            error_log('[canopy] ' . $e->getMessage());
            return null;
        }

        $results = $data['amazonProductSearchResults']['productResults']['results'] ?? [];
        $products = [];
        foreach ((array) $results as $item) {
            $products[] = [
                'asin'          => (string) ($item['asin'] ?? ''),
                'title'         => trim((string) ($item['title'] ?? '')),
                'url'           => (string) ($item['url'] ?? ''),
                'price'         => isset($item['price']['value']) && $item['price']['value'] !== null
                                    ? (float) $item['price']['value'] : null,
                'rating'        => isset($item['rating']) && $item['rating'] !== null ? (float) $item['rating'] : null,
                'ratings_total' => (int) ($item['ratingsTotal'] ?? 0),
            ];
        }
        $products = array_values(array_filter($products, fn ($p) => $p['title'] !== ''));

        self::cachePut($cacheKey, $products);
        return $products ?: null;
    }

    /** Appel de diagnostic SANS cache (route canopy/test) : résultat brut ou exception. */
    public static function test(): array
    {
        if (!self::enabled()) {
            throw new \RuntimeException('Clé API Canopy absente : collez-la dans l\'écran Connecteurs.');
        }
        $data = self::graphql(self::SEARCH_QUERY, ['searchTerm' => 'carnet de notes', 'domain' => self::domain(), 'page' => '1']);
        $results = $data['amazonProductSearchResults']['productResults']['results'] ?? [];
        return [
            'results_count' => count((array) $results),
            'sample'        => array_slice(array_map(
                fn ($r) => ['title' => $r['title'] ?? '?', 'price' => $r['price']['display'] ?? null, 'rating' => $r['rating'] ?? null],
                (array) $results
            ), 0, 3),
            'usage'         => self::status(),
        ];
    }

    // ── Interne ────────────────────────────────────────────────────────────

    /** @throws \RuntimeException en cas d'erreur HTTP ou GraphQL */
    private static function graphql(string $query, array $variables): array
    {
        $cfg = Config::get('canopy');
        $payload = json_encode(['query' => $query, 'variables' => $variables], JSON_UNESCAPED_UNICODE);

        $ch = curl_init((string) ($cfg['endpoint'] ?? 'https://graphql.canopyapi.co/'));
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'API-KEY: ' . trim((string) Settings::get('canopy.api_key', '')),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => (int) ($cfg['timeout'] ?? 30),
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $curlError = $response === false ? (string) curl_error($ch) : '';
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        self::bumpUsage(); // chaque appel réel compte, même en erreur, pour rester prudent

        if ($curlError !== '') {
            throw new \RuntimeException('Canopy réseau : ' . $curlError);
        }
        $decoded = json_decode((string) $response, true);
        if ($code === 401 || $code === 403) {
            throw new \RuntimeException('Canopy : clé API refusée (HTTP ' . $code . ').');
        }
        if ($code === 429) {
            throw new \RuntimeException('Canopy : limite de requêtes atteinte (HTTP 429).');
        }
        if ($code !== 200 || !is_array($decoded)) {
            throw new \RuntimeException('Canopy HTTP ' . $code . ' : ' . mb_substr((string) $response, 0, 200));
        }
        if (!empty($decoded['errors'])) {
            $message = $decoded['errors'][0]['message'] ?? 'erreur GraphQL';
            throw new \RuntimeException('Canopy GraphQL : ' . mb_substr((string) $message, 0, 200));
        }
        return (array) ($decoded['data'] ?? []);
    }

    private static function cacheGet(string $key): ?array
    {
        $file = self::cacheDir() . '/canopy-' . $key . '.json';
        $ttl = (int) Config::get('canopy.cache_ttl', 604800);
        if (!is_file($file) || (time() - (int) filemtime($file)) > $ttl) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    private static function cachePut(string $key, array $data): void
    {
        @file_put_contents(self::cacheDir() . '/canopy-' . $key . '.json', json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    private static function usage(): int
    {
        $data = json_decode((string) @file_get_contents(self::usageFile()), true) ?: [];
        return (int) ($data[date('Y-m')] ?? 0);
    }

    private static function bumpUsage(): void
    {
        $file = self::usageFile();
        $data = json_decode((string) @file_get_contents($file), true) ?: [];
        $month = date('Y-m');
        $data = [$month => (int) ($data[$month] ?? 0) + 1]; // on ne garde que le mois courant
        @file_put_contents($file, json_encode($data));
    }

    private static function usageFile(): string
    {
        return self::cacheDir() . '/canopy-usage.json';
    }

    private static function cacheDir(): string
    {
        $dir = (string) Config::get('paths.cache');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }
}
