<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Settings;
use App\Core\Util;

/**
 * Client natif (curl) de l'API Google Gemini — generateContent.
 * Aucune dépendance externe.
 */
final class Gemini
{
    /**
     * Génère du texte brut.
     *
     * @param array $opts  system, temperature, model ('fast'|'pro'|id complet),
     *                     search (bool), json (bool), max_tokens
     */
    public static function text(string $prompt, array $opts = []): string
    {
        $cfg = Config::get('gemini');
        $apiKey = trim((string) Settings::get('gemini.api_key', ''));
        if ($apiKey === '') {
            throw new \RuntimeException("Clé API Gemini absente : collez-la dans Connecteurs (icône ⚡ en haut à droite) ou dans config/config.php.");
        }

        $model = $opts['model'] ?? 'fast';
        if ($model === 'fast') {
            $model = (string) Settings::get('gemini.model_fast', $cfg['model_fast']);
        } elseif ($model === 'pro') {
            $model = (string) Settings::get('gemini.model_pro', $cfg['model_pro']);
        }

        $generation = [
            'temperature'     => $opts['temperature'] ?? 0.8,
            'maxOutputTokens' => $opts['max_tokens'] ?? (int) ($cfg['max_output_tokens'] ?? 8192),
        ];

        $body = [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $prompt]]],
            ],
            'generationConfig' => $generation,
        ];
        if (!empty($opts['system'])) {
            $body['systemInstruction'] = ['parts' => [['text' => $opts['system']]]];
        }
        if (!empty($opts['search'])) {
            // Ancrage Google Search : incompatible avec le mode JSON strict,
            // le JSON est alors demandé dans le prompt et extrait de façon tolérante.
            $body['tools'] = [['google_search' => (object) []]];
        } elseif (!empty($opts['json'])) {
            $body['generationConfig']['responseMimeType'] = 'application/json';
        }

        $url = rtrim($cfg['endpoint'], '/') . '/models/' . rawurlencode($model)
             . ':generateContent?key=' . rawurlencode($apiKey);

        $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
        $retries = array_key_exists('retries', $opts)
            ? max(0, (int) $opts['retries'])
            : max(0, (int) ($cfg['max_retries'] ?? 2));
        $timeout = (int) ($opts['timeout'] ?? $cfg['timeout'] ?? 180);
        $lastError = 'Erreur inconnue';

        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            if ($attempt > 0) {
                sleep(min(8, 2 ** $attempt));
            }
            [$code, $response, $curlError] = self::post($url, (string) $payload, $cfg, $timeout);

            if ($curlError !== '') {
                $lastError = 'Réseau : ' . $curlError;
                continue;
            }
            $data = json_decode($response, true);
            if ($code >= 500 || $code === 429) {
                $lastError = 'API Gemini HTTP ' . $code;
                continue;
            }
            if ($code !== 200 || !is_array($data)) {
                $message = is_array($data) ? ($data['error']['message'] ?? $response) : $response;
                throw new \RuntimeException('API Gemini (' . $code . ') : ' . mb_substr((string) $message, 0, 300));
            }

            $parts = $data['candidates'][0]['content']['parts'] ?? [];
            $text = '';
            foreach ($parts as $part) {
                $text .= $part['text'] ?? '';
            }
            if (trim($text) === '') {
                $reason = $data['candidates'][0]['finishReason'] ?? ($data['promptFeedback']['blockReason'] ?? 'vide');
                $lastError = 'Réponse vide du modèle (' . $reason . ')';
                continue;
            }
            return $text;
        }

        throw new \RuntimeException('Gemini indisponible après relances — ' . $lastError);
    }

    /** Génère et décode un objet JSON. */
    public static function json(string $prompt, array $opts = []): array
    {
        $useSearch = $opts['search'] ?? (bool) Config::get('gemini.use_google_search', true);
        $opts['search'] = $useSearch;
        $opts['json'] = !$useSearch;
        $text = self::text($prompt, $opts);
        return Util::extractJson($text);
    }

    /**
     * Génère une IMAGE (modèle « nano banana ») et renvoie ses octets bruts.
     *
     * @param string  $prompt         description de l'image souhaitée
     * @param ?string $referencePath  image de référence optionnelle (JPEG/PNG)
     *                                dont le modèle s'inspire
     */
    public static function image(string $prompt, ?string $referencePath = null): string
    {
        $cfg = Config::get('gemini');
        $apiKey = trim((string) Settings::get('gemini.api_key', ''));
        if ($apiKey === '') {
            throw new \RuntimeException("Clé API Gemini absente : collez-la dans ⚡ Connecteurs.");
        }
        $model = (string) ($cfg['model_image'] ?? 'gemini-2.5-flash-image');

        $parts = [['text' => $prompt]];
        if ($referencePath !== null && is_file($referencePath)) {
            $mime = str_ends_with(strtolower($referencePath), '.png') ? 'image/png' : 'image/jpeg';
            $parts[] = ['inlineData' => [
                'mimeType' => $mime,
                'data'     => base64_encode((string) file_get_contents($referencePath)),
            ]];
        }
        $body = [
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => ['responseModalities' => ['IMAGE']],
        ];

        $url = rtrim($cfg['endpoint'], '/') . '/models/' . rawurlencode($model)
             . ':generateContent?key=' . rawurlencode($apiKey);
        [$code, $response, $curlError] = self::post($url, (string) json_encode($body), $cfg, 120);

        if ($curlError !== '') {
            throw new \RuntimeException('Génération d\'image — réseau : ' . $curlError);
        }
        $data = json_decode($response, true);
        if ($code !== 200 || !is_array($data)) {
            $message = is_array($data) ? ($data['error']['message'] ?? $response) : $response;
            throw new \RuntimeException('Génération d\'image (' . $code . ') : ' . mb_substr((string) $message, 0, 260));
        }
        foreach ((array) ($data['candidates'][0]['content']['parts'] ?? []) as $part) {
            if (isset($part['inlineData']['data'])) {
                $binary = base64_decode((string) $part['inlineData']['data'], true);
                if ($binary !== false && $binary !== '') {
                    return $binary;
                }
            }
        }
        $reason = $data['candidates'][0]['finishReason'] ?? 'aucune image dans la réponse';
        throw new \RuntimeException('Génération d\'image : ' . $reason
            . ' — vérifiez que le modèle image « ' . $model . ' » est disponible pour votre clé.');
    }

    /**
     * Liste des modèles Gemini disponibles pour la clé configurée
     * (cache disque 24 h). Vide si clé absente ou API injoignable.
     * @return array<int,array{id:string,label:string}>
     */
    public static function models(): array
    {
        $cfg = Config::get('gemini');
        $apiKey = trim((string) Settings::get('gemini.api_key', ''));
        if ($apiKey === '') {
            return [];
        }
        $cacheDir = (string) Config::get('paths.cache');
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0775, true);
        }
        $cacheFile = $cacheDir . '/gemini-models-' . substr(md5($apiKey), 0, 12) . '.json';
        if (is_file($cacheFile) && (time() - (int) filemtime($cacheFile)) < 86400) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $ch = curl_init(rtrim($cfg['endpoint'], '/') . '/models?pageSize=200&key=' . rawurlencode($apiKey));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $models = [];
        $data = is_string($response) ? json_decode($response, true) : null;
        if ($code === 200 && is_array($data)) {
            foreach ((array) ($data['models'] ?? []) as $model) {
                $name = (string) ($model['name'] ?? '');
                $methods = (array) ($model['supportedGenerationMethods'] ?? []);
                if (!str_starts_with($name, 'models/gemini') || !in_array('generateContent', $methods, true)) {
                    continue;
                }
                $id = substr($name, strlen('models/'));
                // On écarte les variantes non pertinentes pour la rédaction
                if (preg_match('/embedding|aqa|-tts|image|vision|audio|live/i', $id)) {
                    continue;
                }
                $models[] = ['id' => $id, 'label' => (string) ($model['displayName'] ?: $id)];
            }
            usort($models, fn ($a, $b) => strcmp($b['id'], $a['id']));
            file_put_contents($cacheFile, json_encode($models, JSON_UNESCAPED_UNICODE));
        }
        return $models;
    }

    /** @return array{0:int,1:string,2:string} code HTTP, corps, erreur curl */
    private static function post(string $url, string $payload, array $cfg, int $timeout = 180): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => (int) ($cfg['connect_timeout'] ?? 15),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $error = $response === false ? (string) curl_error($ch) : '';
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$code, is_string($response) ? $response : '', $error];
    }
}
