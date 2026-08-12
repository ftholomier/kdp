<?php
declare(strict_types=1);

/**
 * ÉCRITURE AUTONOME — point d'entrée pour la tâche cron de l'hébergeur.
 *
 * Deux façons de l'appeler (toutes les 5 à 10 minutes) :
 *   - URL   : https://votre-domaine.tld/cron.php?key=VOTRE_SECRET
 *   - CLI   : php /chemin/vers/public/cron.php VOTRE_SECRET
 *
 * Le secret s'affiche à l'étape 05 (carte « Écriture autonome »). À chaque
 * passage, le cron rédige jusqu'à 5 sections des projets en cours d'écriture
 * (writing_status = 'running'), avec les mêmes points de contrôle que le
 * navigateur — fermez l'ordinateur, le livre s'écrit tout seul et vous
 * recevez un e-mail à la fin.
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Db;
use App\Core\Settings;
use App\Core\Util;
use App\Services\Writer;

header('Content-Type: application/json; charset=utf-8');

$provided = $_GET['key'] ?? ($argv[1] ?? '');
$secret = trim((string) Settings::get('cron.secret', ''));
if ($secret === '' || !hash_equals($secret, (string) $provided)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Clé cron invalide — récupérez-la à l\'étape 05 de l\'application.']);
    exit;
}

\App\Core\Migrations::run();
@set_time_limit(280);
$deadline = time() + 240;           // marge sous les limites d'exécution mutualisées
$maxSections = 5;                    // par passage : ~1 section/minute
$log = [];

$projects = Db::all("SELECT * FROM projects WHERE writing_status = 'running' ORDER BY updated_at ASC");
foreach ($projects as $project) {
    while ($maxSections > 0 && time() < $deadline) {
        $concept = $project['concept_id']
            ? Db::one('SELECT * FROM concepts WHERE id = ? AND project_id = ?', [(int) $project['concept_id'], (int) $project['id']])
            : null;
        try {
            $status = Writer::tick($project, $concept);
        } catch (\Throwable $e) {
            $log[] = ['project' => (int) $project['id'], 'error' => mb_substr($e->getMessage(), 0, 160)];
            break; // point de contrôle restauré : on réessaie au prochain passage
        }
        $maxSections--;
        $log[] = ['project' => (int) $project['id'], 'progress' => ($status['progress'] ?? '?') . ' %'];
        $project = Db::one('SELECT * FROM projects WHERE id = ?', [(int) $project['id']]);
        if (($project['writing_status'] ?? '') !== 'running') {
            break; // terminé (e-mail parti) ou mis en pause
        }
    }
    if ($maxSections <= 0 || time() >= $deadline) {
        break;
    }
}

if (!$projects) {
    $log[] = ['info' => 'Aucun projet en cours d\'écriture (lancez ou reprenez la rédaction à l\'étape 05).'];
}
echo json_encode(['ok' => true, 'ran_at' => date('c'), 'log' => $log], JSON_UNESCAPED_UNICODE);
