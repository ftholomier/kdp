<?php
declare(strict_types=1);

/**
 * Installation : création des tables + premier compte utilisateur.
 * Se verrouille dès qu'un utilisateur existe.
 */
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Config;
use App\Core\Db;

$messages = [];
$errors = [];
$installed = false;
$dbOk = false;

try {
    Db::pdo();
    $dbOk = true;
} catch (Throwable $e) {
    $errors[] = 'Connexion MySQL impossible : ' . htmlspecialchars($e->getMessage())
        . ' — vérifiez la section « db » de config/config.php (créez la base dans phpMyAdmin si besoin).';
}

if ($dbOk) {
    try {
        $sql = (string) file_get_contents(APP_ROOT . '/database/schema.sql');
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            if ($statement !== '' && stripos($statement, 'SET NAMES') !== 0) {
                Db::pdo()->exec($statement);
            }
        }
        $messages[] = 'Tables vérifiées/créées.';
    } catch (Throwable $e) {
        $errors[] = 'Création des tables : ' . htmlspecialchars($e->getMessage());
    }

    try {
        $count = (int) (Db::one('SELECT COUNT(*) AS n FROM users')['n'] ?? 0);
        if ($count > 0) {
            $installed = true;
        } elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
            $name = trim((string) ($_POST['name'] ?? ''));
            $pass = (string) ($_POST['password'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Adresse e-mail invalide.';
            } elseif (mb_strlen($pass) < 8) {
                $errors[] = 'Mot de passe : 8 caractères minimum.';
            } else {
                Db::run(
                    'INSERT INTO users (email, password_hash, display_name, created_at) VALUES (?,?,?,?)',
                    [$email, password_hash($pass, Config::get('security.password_algo')), $name ?: 'Auteur', Db::now()]
                );
                $installed = true;
                $messages[] = 'Compte créé — vous pouvez vous connecter.';
            }
        }
    } catch (Throwable $e) {
        $errors[] = htmlspecialchars($e->getMessage());
    }
}

$geminiOk = (string) Config::get('gemini.api_key', '') !== '';
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Installation — <?= htmlspecialchars((string) Config::get('app.name', 'Tirage')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=Instrument+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<div class="auth-wrap">
  <div class="auth-card">
    <div class="auth-brand"><div class="logo-mark">T</div><div class="logo-name"><?= htmlspecialchars((string) Config::get('app.name', 'Tirage')) ?></div></div>
    <h1 class="serif" style="font-size:30px; margin:18px 0 6px;">Installation</h1>
    <p class="muted" style="margin:0 0 18px;">Création des tables MySQL et du compte de connexion.</p>

    <?php foreach ($messages as $m): ?><div class="note note-ok">✓ <?= $m ?></div><?php endforeach; ?>
    <?php foreach ($errors as $m): ?><div class="note note-warn">! <?= $m ?></div><?php endforeach; ?>
    <?php if (!$geminiOk): ?><div class="note note-warn">! Clé API Gemini absente — renseignez <code>gemini.api_key</code> dans <code>config/config.php</code> (https://aistudio.google.com/apikey).</div><?php endif; ?>

    <?php if ($installed): ?>
      <div class="note note-ok">✓ Application installée. Par sécurité, supprimez <code>public/setup.php</code> du serveur.</div>
      <a class="btn btn-primary" style="display:block; text-align:center; margin-top:14px;" href="index.php">Ouvrir l'application</a>
    <?php elseif ($dbOk): ?>
      <form method="post" class="auth-form">
        <label>Adresse e-mail<input type="email" name="email" required autocomplete="username"></label>
        <label>Nom affiché<input type="text" name="name" placeholder="Ex. : Marc L." autocomplete="name"></label>
        <label>Mot de passe (8 caractères min.)<input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
        <button class="btn btn-primary" type="submit">Créer mon compte</button>
      </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
