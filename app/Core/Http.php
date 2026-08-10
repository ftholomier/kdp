<?php
declare(strict_types=1);

namespace App\Core;

final class Http
{
    private static ?array $input = null;

    public static function json(mixed $data, int $code = 200): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        // JSON_INVALID_UTF8_SUBSTITUTE : un message d'erreur contenant des
        // octets non-UTF8 (réponse brute d'une API tierce) ne doit jamais
        // faire échouer json_encode et priver le client du message.
        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
        echo $json !== false ? $json : '{"ok":false,"error":"Réponse non encodable."}';
        exit;
    }

    public static function error(string $message, int $code = 400, array $extra = []): never
    {
        self::json(['ok' => false, 'error' => $message] + $extra, $code);
    }

    public static function ok(array $data = []): never
    {
        self::json(['ok' => true] + $data);
    }

    /** Corps JSON de la requête (fusionné avec $_POST en secours). */
    public static function input(): array
    {
        if (self::$input === null) {
            $raw = file_get_contents('php://input') ?: '';
            $decoded = json_decode($raw, true);
            self::$input = is_array($decoded) ? $decoded : $_POST;
        }
        return self::$input;
    }

    public static function in(string $key, mixed $default = null): mixed
    {
        return self::input()[$key] ?? $_GET[$key] ?? $default;
    }

    public static function requirePost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            self::error('Méthode non autorisée.', 405);
        }
    }

    public static function cors(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}
