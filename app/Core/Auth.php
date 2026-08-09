<?php
declare(strict_types=1);

namespace App\Core;

final class Auth
{
    private static ?array $user = null;

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $lifetime = (int) Config::get('security.session_lifetime', 604800);
        session_name((string) Config::get('security.session_name', 'tirage_session'));
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        ]);
        session_start();
    }

    public static function attempt(string $email, string $password): bool
    {
        self::startSession();

        // Anti force brute : pause après N échecs consécutifs.
        $max   = (int) Config::get('security.login_throttle', 5);
        $pause = (int) Config::get('security.login_pause', 300);
        $fails = $_SESSION['login_fails'] ?? 0;
        $last  = $_SESSION['login_last_fail'] ?? 0;
        if ($fails >= $max && (time() - $last) < $pause) {
            return false;
        }

        $user = Db::one('SELECT * FROM users WHERE email = ?', [mb_strtolower(trim($email))]);
        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['id'];
            unset($_SESSION['login_fails'], $_SESSION['login_last_fail']);
            Db::run('UPDATE users SET last_login_at = ? WHERE id = ?', [Db::now(), $user['id']]);
            self::$user = $user;
            return true;
        }

        $_SESSION['login_fails'] = $fails + 1;
        $_SESSION['login_last_fail'] = time();
        return false;
    }

    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        self::$user = null;
    }

    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }
        self::startSession();
        $id = $_SESSION['user_id'] ?? null;
        if (!$id) {
            return null;
        }
        self::$user = Db::one('SELECT * FROM users WHERE id = ?', [(int) $id]);
        return self::$user;
    }

    public static function requireUser(): array
    {
        $user = self::user();
        if (!$user) {
            Http::error('Authentification requise.', 401);
        }
        return $user;
    }

    /** Authentification par jeton d'API (userscript KDP). */
    public static function userByToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $row = Db::one(
            'SELECT u.* FROM api_tokens t JOIN users u ON u.id = t.user_id WHERE t.token = ?',
            [$token]
        );
        if ($row) {
            Db::run('UPDATE api_tokens SET last_used_at = ? WHERE token = ?', [Db::now(), $token]);
        }
        return $row;
    }
}
