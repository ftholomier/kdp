<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

/**
 * URL d'un asset public suffixée de la date de modification du fichier
 * (?v=timestamp) : le navigateur recharge automatiquement JS/CSS dès qu'ils
 * changent — plus besoin de vider le cache après un déploiement.
 */
function kdp_asset(string $rel): string
{
    $rel = ltrim($rel, '/');
    $file = APP_ROOT . '/public/' . $rel;
    $version = is_file($file) ? (string) filemtime($file) : (string) time();
    return $rel . '?v=' . $version;
}

spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $path = APP_ROOT . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

$config = App\Core\Config::all();

date_default_timezone_set($config['app']['timezone'] ?? 'Europe/Paris');
mb_internal_encoding('UTF-8');

if (!empty($config['app']['debug'])) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED);
}

ini_set('log_errors', '1');
$logDir = $config['paths']['logs'] ?? (APP_ROOT . '/storage/logs');
if (is_dir($logDir) && is_writable($logDir)) {
    ini_set('error_log', $logDir . '/php-error.log');
}
