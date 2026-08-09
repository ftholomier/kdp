<?php
declare(strict_types=1);

namespace App\Core;

final class Config
{
    private static ?array $data = null;

    public static function all(): array
    {
        if (self::$data === null) {
            $base = require APP_ROOT . '/config/config.php';
            $localFile = APP_ROOT . '/config/config.local.php';
            if (is_file($localFile)) {
                $local = require $localFile;
                if (is_array($local)) {
                    $base = self::merge($base, $local);
                }
            }
            self::$data = $base;
        }
        return self::$data;
    }

    /** Accès par chemin pointé : Config::get('gemini.api_key') */
    public static function get(string $path, mixed $default = null): mixed
    {
        $node = self::all();
        foreach (explode('.', $path) as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return $default;
            }
            $node = $node[$key];
        }
        return $node;
    }

    public static function baseUrl(): string
    {
        $configured = rtrim((string) self::get('app.base_url', ''), '/');
        if ($configured !== '') {
            return $configured;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/.');
        return $scheme . '://' . $host . $dir;
    }

    private static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && !array_is_list($value)) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }
}
