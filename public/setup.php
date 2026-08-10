<?php
declare(strict_types=1);

/**
 * Installation / configuration.
 *
 * Écrit vos réglages (connexion MySQL + clés API) dans config/config.local.php,
 * un fichier NON versionné : un transfert du projet ne l'écrasera JAMAIS.
 * Puis crée les tables et le premier compte. Se verrouille une fois installé.
 */
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Config;
use App\Core\Db;
use App\Core\Settings;

$messages = [];
$errors = [];
$installed = false;
$dbOk = false;
$showConfigForm = false;
$manualContent = null;

$localPath = APP_ROOT . '/config/config.local.php';
$configDir = APP_ROOT . '/config';

/** Génère le contenu de config.local.php (n'inclut que ce qui est renseigné). */
function build_local_config(array $db, array $keys, string $baseUrl): string
{
    $v = fn ($x) => var_export($x, true);
    $out = "<?php\n";
    $out .= "// Réglages de Tirage — fichier NON versionné : un transfert du projet ne l'écrase jamais.\n";
    $out .= "// Généré par setup.php le " . date('Y-m-d H:i') . ". Éditez-le librement à la main si besoin.\n";
    $out .= "return [\n";
    if ($baseUrl !== '') {
        $out .= "    'app' => ['base_url' => {$v($baseUrl)}],\n";
    }
    $out .= "    'db' => [\n";
    $out .= "        'host' => {$v($db['host'])},\n";
    $out .= "        'name' => {$v($db['name'])},\n";
    $out .= "        'user' => {$v($db['user'])},\n";
    $out .= "        'pass' => {$v($db['pass'])},\n";
    $out .= "    ],\n";
    if (($keys['gemini'] ?? '') !== '') {
        $out .= "    'gemini' => ['api_key' => {$v($keys['gemini'])}],\n";
    }
    if (($keys['canopy'] ?? '') !== '') {
        $out .= "    'canopy' => ['api_key' => {$v($keys['canopy'])}],\n";
    }
    $out .= "];\n";
    return $out;
}

// ── Étape « configuration » : écriture de config.local.php ─────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'config') {
    $db = [
        'host' => trim((string) ($_POST['db_host'] ?? 'localhost')),
        'name' => trim((string) ($_POST['db_name'] ?? '')),
        'user' => trim((string) ($_POST['db_user'] ?? '')),
        'pass' => (string) ($_POST['db_pass'] ?? ''),
    ];
    $keys = [
        'gemini' => trim((string) ($_POST['gemini_key'] ?? '')),
        'canopy' => trim((string) ($_POST['canopy_key'] ?? '')),
    ];
    $baseUrl = rtrim(trim((string) ($_POST['base_url'] ?? '')), '/');

    // Test réel de la connexion avant d'écrire quoi que ce soit
    $connectionOk = false;
    try {
        new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $db['host'], $db['name']),
            $db['user'], $db['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $connectionOk = true;
    } catch (Throwable $e) {
        $errors[] = 'Connexion MySQL refusée : ' . htmlspecialchars($e->getMessage())
            . ' — vérifiez le nom de la base (créée dans phpMyAdmin ?), l\'utilisateur et le mot de passe.';
    }

    if ($connectionOk) {
        $content = build_local_config($db, $keys, $baseUrl);
        if (is_writable($configDir) && @file_put_contents($localPath, $content) !== false) {
            @chmod($localPath, 0640);
            header('Location: setup.php?configured=1');
            exit;
        }
        // Dossier config non inscriptible : on affiche le contenu à déposer à la main
        $manualContent = $content;
        $errors[] = 'Le dossier <code>config/</code> n\'est pas inscriptible par le serveur. '
            . 'Créez le fichier <code>config/config.local.php</code> avec le contenu ci-dessous, puis rechargez.';
    }
    $showConfigForm = true;
}

// ── Connexion à la base (via la config effective) ──────────────────────────
if (!$showConfigForm) {
    try {
        Db::pdo();
        $dbOk = true;
        if (isset($_GET['configured'])) {
            $messages[] = 'Réglages enregistrés dans config/config.local.php — ce fichier ne sera plus jamais écrasé par un transfert.';
        }
    } catch (Throwable $e) {
        // Pas de connexion → on propose le formulaire de configuration
        $showConfigForm = true;
        if (!isset($_GET['configured'])) {
            $errors[] = 'Aucune connexion MySQL active. Renseignez vos accès ci-dessous : ils seront enregistrés dans un fichier protégé.';
        }
    }
}

// ── Création des tables + compte ───────────────────────────────────────────
if ($dbOk) {
    try {
        $sql = (string) file_get_contents(APP_ROOT . '/database/schema.sql');
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            if ($statement !== '' && stripos($statement, 'SET NAMES') !== 0) {
                Db::pdo()->exec($statement);
            }
        }
        $messages[] = 'Tables vérifiées / créées.';
    } catch (Throwable $e) {
        $errors[] = 'Création des tables : ' . htmlspecialchars($e->getMessage());
    }

    try {
        $count = (int) (Db::one('SELECT COUNT(*) AS n FROM users')['n'] ?? 0);
        if ($count > 0) {
            $installed = true;
        } elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'account') {
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

$geminiOk = $dbOk && (string) Settings::get('gemini.api_key', '') !== '';
$curDb = Config::get('db');
$e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Installation — <?= $e(Config::get('app.name', 'Tirage')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=Instrument+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= kdp_asset('assets/css/app.css') ?>">
</head>
<body>
<div class="auth-wrap">
  <div class="auth-card" style="max-width:480px;">
    <div class="auth-brand"><div class="logo-mark">T</div><div class="logo-name"><?= $e(Config::get('app.name', 'Tirage')) ?></div></div>
    <h1 class="serif" style="font-size:30px; margin:18px 0 6px;">Installation</h1>
    <p class="muted" style="margin:0 0 18px;">Vos réglages sont enregistrés dans un fichier protégé, jamais écrasé par un transfert.</p>

    <?php foreach ($messages as $m): ?><div class="note note-ok">✓ <?= $m ?></div><?php endforeach; ?>
    <?php foreach ($errors as $m): ?><div class="note note-warn">! <?= $m ?></div><?php endforeach; ?>

    <?php if ($manualContent !== null): ?>
      <label style="margin-top:12px;">Contenu à copier dans <code>config/config.local.php</code>
        <textarea rows="12" readonly style="font-family:var(--mono); font-size:12px;"><?= $e($manualContent) ?></textarea>
      </label>
      <a class="btn btn-primary" style="display:block; text-align:center; margin-top:12px;" href="setup.php">J'ai créé le fichier — continuer</a>

    <?php elseif ($installed): ?>
      <?php if (!$geminiOk): ?>
        <div class="note note-warn">! Pensez à coller votre clé API Gemini dans l'application (bouton <strong>⚡ Connecteurs</strong>) — obligatoire pour générer.</div>
      <?php endif; ?>
      <div class="note note-ok">✓ Application installée. Par sécurité, supprimez <code>public/setup.php</code> du serveur.</div>
      <a class="btn btn-primary" style="display:block; text-align:center; margin-top:14px;" href="index.php">Ouvrir l'application</a>

    <?php elseif ($showConfigForm): ?>
      <form method="post" class="auth-form">
        <input type="hidden" name="action" value="config">
        <div style="font-size:13px; font-weight:600; margin-bottom:-4px;">Connexion MySQL (phpMyAdmin)</div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
          <label>Hôte<input type="text" name="db_host" value="<?= $e($curDb['host'] ?? 'localhost') ?>" required></label>
          <label>Base de données<input type="text" name="db_name" value="<?= $e($curDb['name'] ?? '') ?>" required></label>
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
          <label>Utilisateur<input type="text" name="db_user" value="<?= $e($curDb['user'] ?? '') ?>" required></label>
          <label>Mot de passe<input type="password" name="db_pass" placeholder="mot de passe MySQL"></label>
        </div>
        <label>URL du site <span class="faint">(optionnel, vide = détection auto)</span>
          <input type="text" name="base_url" placeholder="https://studio.mondomaine.fr">
        </label>
        <div style="font-size:13px; font-weight:600; margin:6px 0 -6px;">Clés API <span class="faint" style="font-weight:400;">(optionnel, modifiables ensuite dans ⚡ Connecteurs)</span></div>
        <label>Clé Gemini<input type="password" name="gemini_key" placeholder="aistudio.google.com/apikey"></label>
        <label>Clé Canopy<input type="password" name="canopy_key" placeholder="canopyapi.co (facultatif)"></label>
        <button class="btn btn-primary" type="submit">Tester et enregistrer</button>
      </form>

    <?php elseif ($dbOk): ?>
      <form method="post" class="auth-form">
        <input type="hidden" name="action" value="account">
        <div style="font-size:13px; font-weight:600; margin-bottom:-4px;">Votre compte de connexion</div>
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
