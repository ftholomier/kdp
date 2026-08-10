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
    query Search($searchTerm: String!, $domain: AmazonDomain!, $page: BigInt) {
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
        $store = self::usageStore();
        $month = date('Y-m');
        $configBudget = (int) Config::get('canopy.monthly_budget', 100);

        // Source de vérité prioritaire : le quota réel renvoyé par Canopy dans
        // ses en-têtes lors du dernier appel de ce mois-ci.
        if (($store['real_month'] ?? '') === $month && isset($store['real_used'])) {
            $used = (int) $store['real_used'];
            $budget = (int) ($store['real_limit'] ?? $configBudget);
            $real = true;
        } else {
            $used = (int) ($store[$month] ?? 0);
            $budget = $configBudget;
            $real = false;
        }
        return [
            'enabled'   => self::enabled(),
            'used'      => $used,
            'budget'    => $budget,
            'exhausted' => self::enabled() && $used >= $budget,
            'domain'    => self::domain(),
            'real'      => $real, // true = chiffre en direct de Canopy, false = estimation locale
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
                'page'       => $page, // BigInt : entier attendu par Canopy
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

    /**
     * Diagnostic complet (route canopy/test) — ne LÈVE JAMAIS d'exception :
     * renvoie la vérité brute de l'échange (code HTTP, corps, erreurs GraphQL)
     * pour identifier précisément un éventuel écart de schéma.
     */
    public static function test(): array
    {
        if (!self::enabled()) {
            return ['ok' => false, 'stage' => 'config', 'message' => 'Clé API Canopy absente : collez-la ci-dessus puis Enregistrez avant de tester.'];
        }

        [$code, $response, $curlError, $headers] = self::rawPost(self::SEARCH_QUERY, [
            'searchTerm' => 'carnet de notes', 'domain' => self::domain(), 'page' => 1,
        ]);

        $out = [
            'ok'        => false,
            'http_code' => $code,
            'domain'    => self::domain(),
            'usage'     => self::status(),
            'excerpt'   => mb_substr(preg_replace('/\s+/', ' ', (string) $response) ?? '', 0, 600),
            // En-têtes liés au quota : servent à brancher le compteur sur le
            // vrai chiffre de Canopy (leurs noms exacts nous sont révélés ici).
            'quota_headers' => self::quotaHeaders($headers),
        ];

        if ($curlError !== '') {
            $out['stage'] = 'network';
            $out['message'] = 'Connexion à Canopy impossible : ' . $curlError
                . ' (vérifiez que votre hébergeur autorise les appels HTTPS sortants).';
            return $out;
        }

        $decoded = json_decode((string) $response, true);
        if ($code === 401 || $code === 403) {
            $out['stage'] = 'auth';
            $out['message'] = 'Clé API refusée par Canopy (HTTP ' . $code . '). Vérifiez la clé copiée depuis votre tableau de bord canopyapi.co.';
            return $out;
        }
        if (!is_array($decoded)) {
            $out['stage'] = 'http';
            $out['message'] = 'Réponse inattendue de Canopy (HTTP ' . $code . '). Voir le détail brut ci-dessous.';
            return $out;
        }
        if (!empty($decoded['errors'])) {
            $out['stage'] = 'graphql';
            $out['message'] = 'Canopy a répondu, mais la requête ne correspond pas à son schéma : '
                . mb_substr((string) ($decoded['errors'][0]['message'] ?? 'erreur GraphQL'), 0, 240)
                . ' — copiez ce message, il me permet de corriger la requête.';
            $out['graphql_errors'] = array_map(fn ($e) => (string) ($e['message'] ?? ''), (array) $decoded['errors']);
            return $out;
        }

        // Succès : Canopy a bien servi une réponse → quota réel si dispo, sinon +1 local
        self::recordUsageFromHeaders($headers);
        self::bumpUsage();
        $results = self::pluckResults($decoded['data'] ?? []);
        $out['ok'] = true;
        $out['stage'] = 'success';
        $out['usage'] = self::status(); // compteur rafraîchi après incrément
        $out['results_count'] = count($results);
        $out['sample'] = array_slice(array_map(fn ($r) => [
            'title'  => mb_substr((string) ($r['title'] ?? '?'), 0, 70),
            'price'  => $r['price']['display'] ?? ($r['price']['value'] ?? null),
            'rating' => $r['rating'] ?? null,
        ], $results), 0, 3);
        $out['message'] = $results
            ? 'Connexion opérationnelle — ' . count($results) . ' résultats Amazon reçus.'
            : 'Canopy a répondu (HTTP 200) mais aucun produit n\'a été extrait : le schéma diffère peut-être. Détail brut ci-dessous.';
        if ($out['quota_headers']) {
            $out['message'] .= ' [quota Canopy détecté : '
                . implode(', ', array_map(fn ($k, $v) => "$k=$v", array_keys($out['quota_headers']), $out['quota_headers']))
                . ']';
        }
        return $out;
    }

    /** Extraction tolérante d'une liste de produits, quel que soit le chemin. */
    private static function pluckResults(array $data): array
    {
        // Chemin attendu
        $path = $data['amazonProductSearchResults']['productResults']['results'] ?? null;
        if (is_array($path) && $path) {
            return $path;
        }
        // Recherche récursive d'un tableau d'objets contenant un titre + asin
        $found = [];
        $walk = function ($node) use (&$walk, &$found): void {
            if (!is_array($node)) {
                return;
            }
            if (isset($node[0]) && is_array($node[0]) && (isset($node[0]['title']) || isset($node[0]['asin']))) {
                $found = $node;
                return;
            }
            foreach ($node as $child) {
                if (!$found) {
                    $walk($child);
                }
            }
        };
        $walk($data);
        return $found;
    }

    // ── Interne ────────────────────────────────────────────────────────────

    /** POST GraphQL brut. @return array{0:int,1:string,2:string,3:array} code, corps, erreur curl, en-têtes */
    private static function rawPost(string $query, array $variables): array
    {
        $cfg = Config::get('canopy');
        $payload = json_encode(['query' => $query, 'variables' => $variables], JSON_UNESCAPED_UNICODE);
        $apiKey = trim((string) Settings::get('canopy.api_key', ''));

        $headers = [];
        $ch = curl_init((string) ($cfg['endpoint'] ?? 'https://graphql.canopyapi.co/'));
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'API-KEY: ' . $apiKey,                 // en-tête documenté par Canopy
                'Authorization: Bearer ' . $apiKey,    // variante Bearer (l'un ou l'autre suffit)
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => (int) ($cfg['timeout'] ?? 30),
            CURLOPT_SSL_VERIFYPEER => true,
            // Capture des en-têtes : beaucoup d'API y renvoient le quota réel restant
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$headers): int {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }
                return strlen($line);
            },
        ]);
        $response = curl_exec($ch);
        $curlError = $response === false ? (string) curl_error($ch) : '';
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$code, is_string($response) ? $response : '', $curlError, $headers];
    }

    /** Repère un éventuel en-tête de quota (nom variable selon les API). */
    private static function quotaHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            if (preg_match('/quota|rate.?limit|credit|usage|remaining|limit/i', $name)) {
                $out[$name] = $value;
            }
        }
        return $out;
    }

    /** @throws \RuntimeException en cas d'erreur HTTP ou GraphQL */
    private static function graphql(string $query, array $variables): array
    {
        [$code, $response, $curlError, $headers] = self::rawPost($query, $variables);

        if ($curlError !== '') {
            throw new \RuntimeException('Canopy réseau : ' . $curlError);
        }
        $decoded = json_decode($response, true);
        if ($code === 401 || $code === 403) {
            throw new \RuntimeException('Canopy : clé API refusée (HTTP ' . $code . ').');
        }
        if ($code === 429) {
            throw new \RuntimeException('Canopy : limite de requêtes atteinte (HTTP 429).');
        }
        if ($code !== 200 || !is_array($decoded)) {
            throw new \RuntimeException('Canopy HTTP ' . $code . ' : ' . mb_substr($response, 0, 200));
        }
        if (!empty($decoded['errors'])) {
            $message = $decoded['errors'][0]['message'] ?? 'erreur GraphQL';
            throw new \RuntimeException('Canopy GraphQL : ' . mb_substr((string) $message, 0, 200));
        }
        // Requête réellement servie par Canopy : on enregistre le quota réel
        // (en-têtes) si disponible, sinon on incrémente le compteur local estimé.
        self::recordUsageFromHeaders($headers);
        self::bumpUsage();
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

    private static function usageStore(): array
    {
        $data = json_decode((string) @file_get_contents(self::usageFile()), true);
        return is_array($data) ? $data : [];
    }

    private static function bumpUsage(): void
    {
        $store = self::usageStore();
        $month = date('Y-m');
        $store[$month] = (int) ($store[$month] ?? 0) + 1;
        @file_put_contents(self::usageFile(), json_encode($store));
    }

    /**
     * Enregistre le quota RÉEL renvoyé par Canopy dans ses en-têtes de réponse
     * (source de vérité prioritaire sur le compteur local estimé).
     */
    private static function recordUsageFromHeaders(array $headers): void
    {
        $limit = null;
        $remaining = null;
        $used = null;
        foreach ($headers as $name => $value) {
            if (!is_numeric($value)) {
                continue;
            }
            $v = (int) $value;
            if (preg_match('/remaining/i', $name)) {
                $remaining = $v;
            } elseif (preg_match('/(used|consumed|count)/i', $name)) {
                $used = $v;
            } elseif (preg_match('/(limit|quota|cap|total)/i', $name) && !preg_match('/(reset|window|per)/i', $name)) {
                $limit = $v;
            }
        }
        if ($remaining === null && $used === null) {
            return; // aucun en-tête de quota exploitable
        }
        if ($used === null) {
            $base = $limit ?? (int) Config::get('canopy.monthly_budget', 100);
            $used = max(0, $base - (int) $remaining);
        }
        $store = self::usageStore();
        $store['real_month'] = date('Y-m');
        $store['real_used']  = $used;
        if ($limit !== null) {
            $store['real_limit'] = $limit;
        }
        @file_put_contents(self::usageFile(), json_encode($store));
    }

    /** Remet à zéro le compteur local (ex. à l'enregistrement d'une nouvelle clé). */
    public static function resetUsage(): void
    {
        @file_put_contents(self::usageFile(), json_encode([date('Y-m') => 0]));
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
