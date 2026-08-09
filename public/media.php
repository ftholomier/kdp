<?php
declare(strict_types=1);

/** Sert les images uploadées (stockées hors racine web), session requise. */
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;
use App\Core\Db;

$user = Auth::user();
if (!$user) {
    http_response_code(401);
    exit('Authentification requise.');
}

$imageId = (int) ($_GET['img'] ?? 0);
$image = Db::one(
    'SELECT i.* FROM images i JOIN projects p ON p.id = i.project_id WHERE i.id = ? AND p.user_id = ?',
    [$imageId, (int) $user['id']]
);
if (!$image || empty($image['filename'])) {
    http_response_code(404);
    exit('Image introuvable.');
}
$file = (string) Config::get('paths.uploads') . '/' . basename((string) $image['filename']);
if (!is_file($file)) {
    http_response_code(404);
    exit('Fichier absent.');
}
header('Content-Type: image/jpeg');
header('Content-Length: ' . (string) filesize($file));
header('Cache-Control: private, max-age=3600');
readfile($file);
