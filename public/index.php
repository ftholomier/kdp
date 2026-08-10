<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Config;

$appName = htmlspecialchars((string) Config::get('app.name', 'Tirage'));
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $appName ?> — Studio de livres Amazon KDP</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=Instrument+Sans:ital,wght@0,400;0,500;0,600;1,400&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<!-- Polices proposées dans l'éditeur de couverture (aperçu fidèle) -->
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital@0;1&family=DM+Serif+Display:ital@0;1&family=Abril+Fatface&family=Lora:ital@0;1&family=Montserrat:wght@400;700&family=Poppins:wght@400;600&family=Oswald&family=Bebas+Neue&family=Josefin+Sans:wght@400;600&family=Nunito:wght@400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= kdp_asset('assets/css/app.css') ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='7' fill='%231B2A4A'/><text x='16' y='23' text-anchor='middle' font-family='Georgia,serif' font-size='20' fill='%23F4EFE4'>T</text></svg>">
</head>
<body>
<div id="app"><div class="boot-splash"><div class="logo-mark logo-mark-lg">T</div></div></div>
<script src="<?= kdp_asset('assets/js/api.js') ?>"></script>
<script src="<?= kdp_asset('assets/js/app.js') ?>"></script>
</body>
</html>
