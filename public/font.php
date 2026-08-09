<?php
declare(strict_types=1);

/**
 * Proxy + cache des polices Google (woff2) servies en même origine :
 * nécessaire pour incorporer les polices dans les SVG de couverture
 * lors de l'export JPG (canvas) — les URL cross-origin y sont interdites.
 */
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;

if (!Auth::user()) {
    http_response_code(401);
    exit;
}

$allowed = [
    'instrument-serif'        => 'https://fonts.gstatic.com/s/instrumentserif/v5/jizBRFtNs2ka5fXjeivQ4LroWlx-2zIZj1bIkNo.woff2',
    'instrument-serif-italic' => 'https://fonts.gstatic.com/s/instrumentserif/v5/jizHRFtNs2ka5fXjeivQ4LroWlx-6zATi3TNgNq55w.woff2',
    'instrument-sans'         => 'https://fonts.gstatic.com/s/instrumentsans/v5/pximypc9vsFDm051Uf6KVwgkfoSxQ0GsQv8ToedPibnr0She1ZuWi3hKpA.woff2',
    'ibm-plex-mono'           => 'https://fonts.gstatic.com/s/ibmplexmono/v19/-F63fjptAgt5VM-kVkqdyU8n5igg1l9kn-s.woff2',
];

$key = preg_replace('/[^a-z0-9-]/', '', (string) ($_GET['f'] ?? ''));
if (!isset($allowed[$key])) {
    http_response_code(404);
    exit;
}

$cacheDir = (string) Config::get('paths.cache');
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0775, true);
}
$cacheFile = $cacheDir . '/font-' . $key . '.woff2';

if (!is_file($cacheFile) || filesize($cacheFile) < 1000) {
    $ch = curl_init($allowed[$key]);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
    ]);
    $data = curl_exec($ch);
    curl_close($ch);
    if (is_string($data) && strlen($data) > 1000) {
        file_put_contents($cacheFile, $data);
    }
}

if (!is_file($cacheFile)) {
    http_response_code(502);
    exit;
}
header('Content-Type: font/woff2');
header('Cache-Control: private, max-age=604800');
header('Content-Length: ' . (string) filesize($cacheFile));
readfile($cacheFile);
