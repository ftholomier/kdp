<?php
declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        Auth::startSession();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function check(): void
    {
        Auth::startSession();
        $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $known = $_SESSION['csrf_token'] ?? '';
        if ($known === '' || !hash_equals($known, $sent)) {
            Http::error('Jeton CSRF invalide — rechargez la page.', 419);
        }
    }
}
