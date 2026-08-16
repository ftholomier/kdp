<?php
declare(strict_types=1);

namespace App\Api;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\Http;
use App\Core\Settings;
use App\Core\Util;
use App\Services\Canopy;
use App\Services\ChapterTools;
use App\Services\CoverStudio;
use App\Services\Concepts;
use App\Services\Covers;
use App\Services\Docx;
use App\Services\Gemini;
use App\Services\Kdp;
use App\Services\Layout;
use App\Services\Market;
use App\Services\PdfBook;
use App\Services\Toc;
use App\Services\Watch;
use App\Services\Writer;

final class Router
{
    public static function dispatch(): void
    {
        $route = (string) ($_GET['r'] ?? '');

        try {
            // ── Routes publiques par jeton (userscript KDP, CORS ouvert) ──
            if (str_starts_with($route, 'kdp/')) {
                self::kdpTokenRoutes($route);
            }

            // ── Authentification ──
            if ($route === 'auth/login') {
                Http::requirePost();
                $input = Http::input();
                if (Auth::attempt((string) ($input['email'] ?? ''), (string) ($input['password'] ?? ''))) {
                    Http::ok(['user' => self::publicUser(Auth::user()), 'csrf' => Csrf::token()]);
                }
                Http::error('Identifiants incorrects (ou trop de tentatives, patientez 5 min).', 401);
            }
            if ($route === 'auth/logout') {
                Auth::logout();
                Http::ok();
            }
            if ($route === 'auth/me') {
                $user = Auth::user();
                $canopy = null;
                if ($user) {
                    try {
                        $canopy = Canopy::status();
                    } catch (\Throwable $e) {
                        $canopy = null;
                    }
                }
                Http::ok(['user' => $user ? self::publicUser($user) : null, 'csrf' => $user ? Csrf::token() : null,
                          'app' => ['name' => Config::get('app.name'), 'base_url' => Config::baseUrl(), 'canopy' => $canopy]]);
            }

            // ── Tout le reste exige la session ──
            $user = Auth::requireUser();
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
                Csrf::check();
            }
            // Libère le verrou du fichier de session : les appels longs (IA)
            // ne bloquent plus les autres requêtes du même navigateur.
            session_write_close();

            self::authedRoutes($route, $user);
            Http::error('Route inconnue : ' . $route, 404);
        } catch (\PDOException $e) {
            error_log('[api] PDO: ' . $e->getMessage());
            $debug = (bool) Config::get('app.debug');
            Http::error($debug ? 'BDD : ' . $e->getMessage() : 'Erreur base de données — vérifiez config/config.php et l\'installation (setup.php).', 500);
        } catch (\RuntimeException $e) {
            Http::error($e->getMessage(), 502);
        } catch (\Throwable $e) {
            error_log('[api] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            // Application mono-utilisateur : on affiche toujours le vrai message
            // (plus de « Erreur interne » muet impossible à diagnostiquer).
            Http::error(
                mb_substr($e->getMessage(), 0, 300) . ' [' . basename($e->getFile()) . ':' . $e->getLine() . ']',
                500
            );
        }
    }

    // ── Routes authentifiées ───────────────────────────────────────────────

    private static function authedRoutes(string $route, array $user): void
    {
        $userId = (int) $user['id'];

        switch ($route) {
            // ── Projets ──
            case 'projects/list':
                $projects = Db::all(
                    'SELECT p.*, c.title AS concept_title FROM projects p
                     LEFT JOIN concepts c ON c.id = p.concept_id
                     WHERE p.user_id = ? ORDER BY p.updated_at DESC', [$userId]
                );
                Http::ok(['projects' => $projects]);

            case 'projects/create':
                Http::requirePost();
                $id = Db::insert(
                    'INSERT INTO projects (user_id, title, created_at, updated_at) VALUES (?,?,?,?)',
                    [$userId, 'Nouveau livre', Db::now(), Db::now()]
                );
                Http::ok(['project' => self::project($id, $userId)]);

            case 'projects/get':
                Http::ok(self::projectBundle((int) Http::in('id'), $userId));

            case 'projects/update':
                Http::requirePost();
                self::updateProject((int) Http::in('id'), $userId);
                Http::ok(['project' => self::project((int) Http::in('id'), $userId)]);

            case 'projects/delete':
                Http::requirePost();
                $project = self::project((int) Http::in('id'), $userId);
                Db::run('DELETE FROM projects WHERE id = ?', [(int) $project['id']]);
                Http::ok();

            case 'projects/duplicate':
                // Nouveau livre avec la MÊME recette : format, thème, composeur,
                // photos, ton, palette et gabarit de couverture — contenu vierge.
                Http::requirePost();
                $project = self::project((int) Http::in('id'), $userId);
                $newId = Db::insert(
                    'INSERT INTO projects (user_id, title, step, mode, idea, pages, photos, photos_per, photo_style, tone, trim_format, interior_theme, layout_options, created_at, updated_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [
                        $userId,
                        mb_substr((string) $project['title'] . ' — copie', 0, 250),
                        1,
                        (string) $project['mode'],
                        '',
                        (int) $project['pages'],
                        (int) $project['photos'],
                        (int) $project['photos_per'],
                        (string) $project['photo_style'],
                        (string) $project['tone'],
                        (string) $project['trim_format'],
                        (string) ($project['interior_theme'] ?? 'editorial'),
                        $project['layout_options'] ?? null,
                        Db::now(), Db::now(),
                    ]
                );
                $coverRow = Db::one('SELECT template, palette FROM covers WHERE project_id = ?', [(int) $project['id']]);
                if ($coverRow) {
                    Db::run(
                        'INSERT INTO covers (project_id, template, palette, texts, updated_at) VALUES (?,?,?,?,?)',
                        [$newId, (string) $coverRow['template'], (string) $coverRow['palette'], '{}', Db::now()]
                    );
                }
                Util::journal($newId, 'ok', 'Projet créé par duplication de « ' . $project['title'] . ' » (réglages conservés)');
                Http::ok(['id' => $newId, 'projects' => Db::all(
                    'SELECT p.*, c.title AS concept_title FROM projects p LEFT JOIN concepts c ON c.id = p.concept_id WHERE p.user_id = ? ORDER BY p.updated_at DESC', [$userId]
                )]);

            case 'projects/export':
                // Sauvegarde COMPLÈTE du projet (JSON, images en base64)
                @set_time_limit(180);
                $project = self::project((int) Http::in('id'), $userId);
                $payload = json_encode(\App\Services\Backup::export($project), JSON_UNESCAPED_UNICODE);
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="tirage-' . Util::slug((string) $project['title']) . '-' . date('Ymd') . '.json"');
                header('Content-Length: ' . strlen((string) $payload));
                header('Cache-Control: no-store');
                echo $payload;
                exit;

            case 'projects/import':
                // Restauration d'une sauvegarde comme NOUVEAU projet
                Http::requirePost();
                @set_time_limit(180);
                $file = $_FILES['file'] ?? null;
                if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    Http::error('Fichier de sauvegarde manquant.');
                }
                $data = json_decode((string) file_get_contents($file['tmp_name']), true);
                if (!is_array($data)) {
                    Http::error('Fichier illisible : ce n\'est pas une sauvegarde Tirage.');
                }
                $restored = \App\Services\Backup::import($userId, $data);
                Http::ok([
                    'id' => $restored['id'],
                    'title' => $restored['title'],
                    'projects' => Db::all(
                        'SELECT p.*, c.title AS concept_title FROM projects p LEFT JOIN concepts c ON c.id = p.concept_id WHERE p.user_id = ? ORDER BY p.updated_at DESC', [$userId]
                    ),
                ]);

            // ── Étape 1 : niche ──
            case 'market/analyze':
                Http::requirePost();
                $project = self::project((int) Http::in('id'), $userId);
                $idea = trim((string) Http::in('idea', ''));
                if (mb_strlen($idea) < 15) {
                    Http::error('Décrivez votre idée en quelques lignes (15 caractères minimum).');
                }
                Db::run('UPDATE projects SET idea = ?, mode = \'describe\', updated_at = ? WHERE id = ?', [$idea, Db::now(), $project['id']]);
                $result = Market::analyze($project, $idea);
                Http::ok(['themes' => $result['themes'], 'grounded' => $result['grounded']]);

            case 'market/trends':
                Http::requirePost();
                $project = self::project((int) Http::in('id'), $userId);
                Db::run('UPDATE projects SET mode = \'trends\', updated_at = ? WHERE id = ?', [Db::now(), $project['id']]);
                $result = Market::trends($project);
                Http::ok(['themes' => $result['themes'], 'grounded' => $result['grounded']]);

            case 'themes/select':
                Http::requirePost();
                $project = self::project((int) Http::in('id'), $userId);
                $theme = Db::one('SELECT * FROM themes WHERE id = ? AND project_id = ?', [(int) Http::in('theme_id'), $project['id']]);
                if (!$theme) {
                    Http::error('Thème introuvable.');
                }
                Db::run('UPDATE projects SET theme_id = ?, step = GREATEST(step, 2), updated_at = ? WHERE id = ?', [$theme['id'], Db::now(), $project['id']]);
                Http::ok(['project' => self::project((int) $project['id'], $userId)]);

            // ── Étape 2 : concepts ──
            case 'concepts/generate':
                Http::requirePost();
                $project = self::project((int) Http::in('id'), $userId);
                $theme = self::selectedTheme($project);
                $result = Concepts::generate($project, $theme);
                Http::ok(['concepts' => $result['books'], 'grounded' => $result['grounded']]);

            case 'concepts/select':
                Http::requirePost();
                $project = self::project((int) Http::in('id'), $userId);
                $concept = Db::one('SELECT * FROM concepts WHERE id = ? AND project_id = ?', [(int) Http::in('concept_id'), $project['id']]);
                if (!$concept) {
                    Http::error('Concept introuvable.');
                }
                Db::run(
                    'UPDATE projects SET concept_id = ?, title = ?, pages = ?, step = GREATEST(step, 3), updated_at = ? WHERE id = ?',
                    [$concept['id'], $concept['title'], (int) $concept['pages_est'], Db::now(), $project['id']]
                );
                Http::ok(['project' => self::project((int) $project['id'], $userId)]);

            // ── Étape 3 : sommaire ──
            case 'toc/generate':
                Http::requirePost();
                $project = self::project((int) Http::in('id'), $userId);
                self::updateProject((int) $project['id'], $userId); // applique pages/photos/ton/format envoyés
                $project = self::project((int) $project['id'], $userId);
                $concept = self::bookContext($project);
                Http::ok(['toc' => Toc::generate($project, $concept), 'project' => $project]);

            case 'toc/save':
                Http::requirePost();
                $project = self::project((int) Http::in('id'), $userId);
                $toc = Http::in('toc');
                if (!is_array($toc)) {
                    Http::error('Sommaire invalide.');
                }
                Toc::saveDraft((int) $project['id'], $toc);
                Http::ok();

            case 'toc/validate':
                Http::requirePost();
                $project = self::project((int) Http::in('id'), $userId);
                self::updateProject((int) $project['id'], $userId);
                Toc::validate(self::project((int) $project['id'], $userId));
                Http::ok(['project' => self::project((int) $project['id'], $userId)]);

            case 'toc/add-chapter':
                // « Ajoute un chapitre sur… » — à tout moment, même livre rédigé
                Http::requirePost();
                @set_time_limit(120);
                $project = self::project((int) Http::in('id'), $userId);
                $result = Toc::addChapter($project, self::selectedConceptOrNull($project), (string) Http::in('request', ''));
                $result['project'] = self::project((int) $project['id'], $userId);
                Http::ok($result);

            // ── Étape 4 : couverture ──
            case 'covers/get':
                $project = self::project((int) Http::in('id'), $userId);
                $cover = Covers::get($project, self::selectedConceptOrNull($project), $user);
                $hasIllus = is_file(CoverStudio::illusPath((int) $project['id']));
                $geometry = \App\Services\Layout::geometry($project);
                Http::ok([
                    'cover'            => $cover,
                    'els'              => Covers::frontElements($cover, $hasIllus),
                    'els_back'         => Covers::backElements($cover),
                    'library'          => CoverStudio::illusLibrary((int) $project['id']),
                    'custom'           => CoverStudio::customCovers((int) $project['id']),
                    'fonts'            => CoverStudio::fonts(),
                    'motifs'           => CoverStudio::MOTIFS,
                    'geometry'         => $geometry,
                    'has_illustration' => $hasIllus,
                    'has_reference'    => is_file(CoverStudio::refPath((int) $project['id'])),
                    'default_prompt'   => CoverStudio::defaultPrompt($cover['texts']),
                ]);

            case 'covers/save':
                Http::requirePost();
                $project = self::project((int) Http::in('id'), $userId);
                Covers::get($project, self::selectedConceptOrNull($project), $user);
                Covers::save(
                    (int) $project['id'],
                    (string) Http::in('template', 'editorial'),
                    (array) Http::in('palette', []),
                    (array) Http::in('texts', [])
                );
                Db::run('UPDATE projects SET updated_at = ? WHERE id = ?', [Db::now(), $project['id']]);
                Http::ok(['cover' => Covers::get(self::project((int) $project['id'], $userId), null, $user)]);

            case 'covers/generate-back':
                Http::requirePost();
                $project = self::project((int) Http::in('id'), $userId);
                $concept = self::bookContext($project);
                Http::ok(['generated' => Covers::generateBack($project, $concept, $user)]);

            case 'covers/render':
                $project = self::project((int) Http::in('id'), $userId);
                $cover = Covers::get($project, self::selectedConceptOrNull($project), $user);
                header('Content-Type: image/svg+xml; charset=utf-8');
                header('Cache-Control: no-store');
                echo Covers::render($cover, (string) Http::in('face', 'front'));
                exit;

            // ── Studio de couvertures (éditeur à éléments + illustration IA) ──
            case 'coverstudio/variants':
                Http::requirePost();
                @set_time_limit(180);
                $project = self::project((int) Http::in('id'), $userId);
                $cover = Covers::get($project, self::selectedConceptOrNull($project), $user);
                $hasIllus = is_file(CoverStudio::illusPath((int) $project['id']));
                $illusPath = $hasIllus ? CoverStudio::illusPath((int) $project['id']) : null;
                // Graine PROPRE AU LIVRE : deux projets différents ne reçoivent
                // jamais les mêmes propositions, et « 8 nouvelles couvertures »
                // ne modifie que le livre en cours.
                $variants = CoverStudio::variants(
                    (int) crc32('p' . (int) $project['id'] . '#' . max(1, (int) Http::in('seed', 1)))
                );
                $current = $cover['palette'];
                Http::ok(['variants' => array_map(function ($v) use ($cover, $hasIllus, $illusPath, $current) {
                    $els = CoverStudio::layoutElements($v['layout'], $v['palette'], $v['motif'], $cover['texts'], $hasIllus);
                    return [
                        'layout'  => $v['layout'],
                        'motif'   => $v['motif'],
                        'palette' => $v['palette'],
                        'thumb'   => CoverStudio::thumbnailFromElements($els, $illusPath),
                        'selected'=> ($current['layout'] ?? '') === $v['layout']
                            && ($current['motif'] ?? '') === $v['motif']
                            && ($current['c1'] ?? '') === $v['palette']['c1'],
                    ];
                }, $variants)]);

            case 'coverstudio/select':
                Http::requirePost();
                $project = self::project((int) Http::in('id'), $userId);
                $cover = Covers::get($project, self::selectedConceptOrNull($project), $user);
                $palette = (array) Http::in('palette', []);
                $palette['layout'] = (string) Http::in('layout', 'affiche');
                $palette['motif'] = (string) Http::in('motif', 'blob');
                Covers::save((int) $project['id'], 'studio', $palette, $cover['texts']);
                // La version choisie devient le point de départ ÉDITABLE
                $freshCover = Covers::get(self::project((int) $project['id'], $userId), null, $user);
                $els = CoverStudio::layoutElements(
                    $palette['layout'], $freshCover['palette'], $palette['motif'],
                    $freshCover['texts'], is_file(CoverStudio::illusPath((int) $project['id']))
                );
                Covers::saveLayout((int) $project['id'], $els);
                Http::ok(['cover' => Covers::get(self::project((int) $project['id'], $userId), null, $user)]);

            case 'coverstudio/layout-save':
                Http::requirePost();
                $project = self::project((int) Http::in('id'), $userId);
                Covers::get($project, self::selectedConceptOrNull($project), $user);
                $face = Http::in('face') === 'back' ? 'back' : 'front';
                $els = Covers::saveLayout((int) $project['id'], (array) Http::in('els', []), $face);
                Http::ok(['els' => $els, 'face' => $face]);

            case 'coverstudio/layout-reset':
                // Repart de la 4ème composée automatiquement (annule les retouches)
                Http::requirePost();
                $project = self::project((int) Http::in('id'), $userId);
                $cover = Covers::get($project, self::selectedConceptOrNull($project), $user);
                if (Http::in('face') === 'back') {
                    Db::run('UPDATE covers SET layout_back_json = NULL WHERE project_id = ?', [(int) $project['id']]);
                    Http::ok(['els' => CoverStudio::backElements($cover['palette'], $cover['texts'])]);
                }
                Db::run('UPDATE covers SET layout_json = NULL WHERE project_id = ?', [(int) $project['id']]);
                $fresh = Covers::get(self::project((int) $project['id'], $userId), null, $user);
                Http::ok(['els' => Covers::frontElements($fresh, is_file(CoverStudio::illusPath((int) $project['id'])))]);

            case 'coverstudio/motif':
                $type = in_array($_GET['type'] ?? '', CoverStudio::MOTIFS, true) ? (string) $_GET['type'] : 'blob';
                $hex = fn ($v, $d) => preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $v) ? (string) $v : $d;
                $png = CoverStudio::motifPng($type, $hex($_GET['c1'] ?? '', '#C4571F'), $hex($_GET['c2'] ?? '', '#F4EFE4'));
                header('Content-Type: image/png');
                header('Cache-Control: private, max-age=3600');
                echo $png;
                exit;

            case 'coverstudio/illustration':
                Http::requirePost();
                @set_time_limit(180);
                $project = self::project((int) Http::in('id'), $userId);
                $cover = Covers::get($project, self::selectedConceptOrNull($project), $user);
                $prompt = trim((string) Http::in('prompt', ''));
                if ($prompt === '') {
                    $prompt = CoverStudio::defaultPrompt($cover['texts']);
                }
                CoverStudio::generateIllustration((int) $project['id'], $prompt, $cover['palette']);
                // L'illustration prend toute la couverture : éléments « affiche »
                $palette = $cover['palette'];
                $palette['layout'] = 'affiche';
                Covers::save((int) $project['id'], 'studio', $palette, array_merge($cover['texts'], ['illus_prompt' => $prompt]));
                $freshCover = Covers::get(self::project((int) $project['id'], $userId), null, $user);
                Covers::saveLayout((int) $project['id'], CoverStudio::layoutElements('affiche', $freshCover['palette'], (string) ($palette['motif'] ?? 'blob'), $freshCover['texts'], true));
                Http::ok([
                    'generated' => true,
                    'cover'     => Covers::get(self::project((int) $project['id'], $userId), null, $user),
                    'library'   => CoverStudio::illusLibrary((int) $project['id']),
                ]);

            case 'coverstudio/illus-library':
                // Bibliothèque des illustrations générées pour CE livre
                $project = self::project((int) Http::in('id'), $userId);
                Http::ok(['library' => CoverStudio::illusLibrary((int) $project['id'])]);

            case 'coverstudio/illus-select':
                // Une création de la bibliothèque redevient l'illustration active
                Http::requirePost();
                $project = self::project((int) Http::in('id'), $userId);
                $file = CoverStudio::illusItemPath((int) $project['id'], (string) Http::in('slug', ''));
                if (!is_file($file)) {
                    Http::error('Illustration introuvable dans la bibliothèque.');
                }
                @copy($file, CoverStudio::illusPath((int) $project['id']));
                Http::ok(['library' => CoverStudio::illusLibrary((int) $project['id'])]);

            case 'coverstudio/illus-delete':
                Http::requirePost();
                $project = self::project((int) Http::in('id'), $userId);
                $file = CoverStudio::illusItemPath((int) $project['id'], (string) Http::in('slug', ''));
                if (is_file($file)) {
                    $active = CoverStudio::illusPath((int) $project['id']);
                    $wasActive = is_file($active) && md5_file($active) === md5_file($file);
                    @unlink($file);
                    // L'active supprimée : la plus récente restante prend le relais
                    if ($wasActive) {
                        $rest = CoverStudio::illusLibrary((int) $project['id']);
                        if ($rest) {
                            @copy(CoverStudio::illusItemPath((int) $project['id'], $rest[0]['slug']), $active);
                        } else {
                            @unlink($active);
                        }
                    }
                }
                Http::ok(['library' => CoverStudio::illusLibrary((int) $project['id'])]);

            case 'coverstudio/clear-illustration':
                Http::requirePost();
                $project = self::project((int) Http::in('id'), $userId);
                @unlink(CoverStudio::illusPath((int) $project['id']));
                Http::ok();

            case 'coverstudio/upload-custom':
                // VOTRE couverture déjà prête (JPG/PNG pour l'eBook, PDF pour le broché)
                Http::requirePost();
                @set_time_limit(120);
                $project = self::project((int) ($_POST['id'] ?? 0), $userId);
                $file = $_FILES['file'] ?? null;
                if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    Http::error('Fichier manquant.');
                }
                $ext = strtolower((string) pathinfo((string) $file['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true)) {
                    Http::error('Formats acceptés : PDF (broché complet) ou JPG/PNG (1ère de couverture).');
                }
                $kind = $ext === 'pdf' ? 'wrap' : 'front';
                $dest = CoverStudio::customCoverPath((int) $project['id'], $kind);
                if (!move_uploaded_file($file['tmp_name'], $dest) && !copy($file['tmp_name'], $dest)) {
                    Http::error('Impossible d\'enregistrer le fichier.');
                }
                if ($kind === 'front') {
                    $info = @getimagesize($dest);
                    if (!$info) {
                        @unlink($dest);
                        Http::error('Image illisible.');
                    }
                }
                Util::journal((int) $project['id'], 'ok', 'Couverture personnelle importée ('
                    . ($kind === 'wrap' ? 'PDF broché complet' : 'image de 1ère de couverture') . ') — utilisée telle quelle dans les exports.');
                Http::ok(['custom' => CoverStudio::customCovers((int) $project['id'])]);

            case 'coverstudio/clear-custom':
                Http::requirePost();
                $project = self::project((int) Http::in('id'), $userId);
                foreach (['wrap', 'front'] as $kind) {
                    if (Http::in('kind') === '' || Http::in('kind') === $kind) {
                        @unlink(CoverStudio::customCoverPath((int) $project['id'], $kind));
                    }
                }
                Http::ok(['custom' => CoverStudio::customCovers((int) $project['id'])]);

            case 'coverstudio/upload-ref':
                Http::requirePost();
                $project = self::project((int) ($_POST['id'] ?? 0), $userId);
                $file = $_FILES['file'] ?? null;
                if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    Http::error('Fichier manquant.');
                }
                $info = @getimagesize($file['tmp_name']);
                if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
                    Http::error('Format accepté : JPEG, PNG ou WebP.');
                }
                $src = match ($info[2]) {
                    IMAGETYPE_JPEG => imagecreatefromjpeg($file['tmp_name']),
                    IMAGETYPE_PNG  => imagecreatefrompng($file['tmp_name']),
                    IMAGETYPE_WEBP => imagecreatefromwebp($file['tmp_name']),
                };
                if (!$src) {
                    Http::error('Image illisible.');
                }
                imagejpeg($src, CoverStudio::refPath((int) $project['id']), 92);
                imagedestroy($src);
                Http::ok();

            case 'coverstudio/front':
                @set_time_limit(120);
                $project = self::project((int) Http::in('id'), $userId);
                $customFront = CoverStudio::customCoverPath((int) $project['id'], 'front');
                if (is_file($customFront)) {
                    header('Content-Type: image/jpeg');
                    header('Cache-Control: no-store');
                    if (Http::in('download')) {
                        header('Content-Disposition: attachment; filename="couverture-ebook-' . (int) $project['id'] . '.jpg"');
                    }
                    header('Content-Length: ' . (string) filesize($customFront));
                    readfile($customFront);
                    exit;
                }
                $cover = Covers::get($project, self::selectedConceptOrNull($project), $user);
                $illus = CoverStudio::illusPath((int) $project['id']);
                $els = Covers::frontElements($cover, is_file($illus));
                $jpeg = CoverStudio::jpegFromElements($els, is_file($illus) ? $illus : null);
                header('Content-Type: image/jpeg');
                header('Cache-Control: no-store');
                if (Http::in('download')) {
                    header('Content-Disposition: attachment; filename="couverture-ebook-' . (int) $project['id'] . '.jpg"');
                }
                header('Content-Length: ' . strlen($jpeg));
                echo $jpeg;
                exit;

            case 'coverstudio/illus-file':
                $project = self::project((int) Http::in('id'), $userId);
                $item = trim((string) Http::in('item', ''));
                $file = $item !== ''
                    ? CoverStudio::illusItemPath((int) $project['id'], $item)
                    : CoverStudio::illusPath((int) $project['id']);
                if (!is_file($file)) {
                    http_response_code(404);
                    exit;
                }
                header('Content-Type: image/jpeg');
                header('Cache-Control: private, max-age=60');
                header('Content-Length: ' . (string) filesize($file));
                readfile($file);
                exit;

            case 'coverstudio/back':
                @set_time_limit(120);
                $project = self::project((int) Http::in('id'), $userId);
                $cover = Covers::get($project, self::selectedConceptOrNull($project), $user);
                $jpeg = CoverStudio::jpegFromElements(Covers::backElements($cover), null);
                header('Content-Type: image/jpeg');
                header('Cache-Control: no-store');
                header('Content-Length: ' . strlen($jpeg));
                echo $jpeg;
                exit;

            case 'export/cover-pdf':
                // Couverture broché COMPLÈTE (4ème + tranche + 1ère, fond perdu,
                // zone code-barres, 300 dpi) en UN SEUL PDF — le format exigé
                // par KDP pour l'impression, téléversable tel quel.
                @set_time_limit(300);
                $project = self::project((int) Http::in('id'), $userId);
                // Vous avez importé VOTRE couverture : elle est servie telle quelle
                $customWrap = CoverStudio::customCoverPath((int) $project['id'], 'wrap');
                if (is_file($customWrap)) {
                    self::download($customWrap, 'couverture-broche-kdp.pdf', 'application/pdf');
                }
                $cover = Covers::get($project, self::selectedConceptOrNull($project), $user);
                $geometry = \App\Services\Layout::geometry($project);
                $illus = CoverStudio::illusPath((int) $project['id']);
                $frontEls = Covers::frontElements($cover, is_file($illus));
                $backEls = Covers::backElements($cover);
                $wrap = CoverStudio::wrapImage($frontEls, $backEls, $cover['palette'], $cover['texts'], $geometry, is_file($illus) ? $illus : null);

                $exportDir = (string) Config::get('paths.exports');
                if (!is_dir($exportDir)) {
                    mkdir($exportDir, 0775, true);
                }
                $jpgFile = $exportDir . '/couverture-' . (int) $project['id'] . '-wrap.jpg';
                imagejpeg($wrap, $jpgFile, 95);
                imagedestroy($wrap);

                class_exists(\App\Services\PdfBook::class); // charge MiniPdf
                $mm = fn (float $v): float => $v * 72 / 25.4;
                $bleed = (float) $geometry['bleed_mm'];
                $pageW = $mm($bleed + $geometry['w_mm'] + $geometry['spine_mm'] + $geometry['w_mm'] + $bleed);
                $pageH = $mm($geometry['h_mm'] + 2 * $bleed);
                $pdf = new \App\Services\MiniPdf($pageW, $pageH);
                $pdf->newPage();
                $name = $pdf->addJpeg($jpgFile);
                if ($name !== null) {
                    $pdf->image($name, 0, 0, $pageW, $pageH);
                }

                // Tranche VECTORIELLE par-dessus le JPEG : aplat strictement uni
                // (aucun artefact de compression ne peut y déborder) + titre et
                // auteur en texte vectoriel net, polices incorporées.
                $hexRgb = function (string $hex): array {
                    $hex = ltrim($hex, '#');
                    if (strlen($hex) === 3) {
                        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
                    }
                    return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
                };
                $spineHex = CoverStudio::spineHex($frontEls, $cover['palette']);
                $spineX = $mm($bleed + $geometry['w_mm']);
                $spineWpt = $mm((float) $geometry['spine_mm']);
                $pdf->rectRgb($spineX, 0, $spineWpt, $pageH, $hexRgb($spineHex));

                if ((float) $geometry['spine_mm'] >= 6.35) {
                    $fonts = APP_ROOT . '/app/fonts/';
                    $pdf->addTtf('spineT', $fonts . 'InstrumentSerif-Regular.ttf');
                    $pdf->addTtf('spineA', $fonts . 'IBMPlexMono-Medium.ttf');
                    $fg = $hexRgb(CoverStudio::spineTextHex($spineHex, $cover['palette']));

                    $label = trim((string) ($cover['texts']['title'] ?? ''));
                    $author = mb_strtoupper(trim((string) ($cover['texts']['author'] ?? '')));
                    $margin = $mm(14.0);
                    $gap = $mm(8.0);
                    $available = $pageH - 2 * $margin;

                    $size = max(8.0, min($spineWpt * 0.52, 15.0));
                    $measure = function (float $s) use ($pdf, $label, $author, $gap): array {
                        $titleW = $pdf->width($label, 'spineT', $s);
                        $authorW = $author !== '' ? $pdf->width($author, 'spineA', max(6.5, $s * 0.55), 0.8) + $gap : 0.0;
                        return [$titleW, $authorW];
                    };
                    [$titleW, $authorW] = $measure($size);
                    while ($size > 7.0 && $titleW + $authorW > $available) {
                        $size *= 0.93;
                        [$titleW, $authorW] = $measure($size);
                    }
                    if ($titleW + $authorW > $available && $author !== '') {
                        $author = '';
                        [$titleW, $authorW] = $measure($size);
                    }
                    while ($titleW > $available && mb_strlen($label) > 8) {
                        $label = rtrim(mb_substr($label, 0, -2)) . '…';
                        [$titleW, $authorW] = $measure($size);
                    }

                    // Ligne de base centrée dans l'épaisseur du dos (capitale ≈ 0,7 × corps),
                    // jamais au-dessus de la marge haute (titres très longs)
                    $ty = max($margin, ($pageH - ($titleW + $authorW)) / 2);
                    $pdf->vtext($spineX + $spineWpt / 2 - $size * 0.34, $ty, 'spineT', $size, $label, 0, $fg);
                    if ($author !== '') {
                        $sizeA = max(6.5, $size * 0.55);
                        $pdf->vtext($spineX + $spineWpt / 2 - $sizeA * 0.34, $ty + $titleW + $gap, 'spineA', $sizeA, $author, 0.8, $fg);
                    }
                }
                $file = $exportDir . '/couverture-' . (int) $project['id'] . '-kdp.pdf';
                file_put_contents($file, $pdf->build());
                self::download($file, 'couverture-broche-kdp.pdf', 'application/pdf');

            case 'covers/validate':
                Http::requirePost();
                $project = self::project((int) Http::in('id'), $userId);
                Db::run('UPDATE projects SET step = GREATEST(step, 5), updated_at = ? WHERE id = ?', [Db::now(), $project['id']]);
                Http::ok(['project' => self::project((int) $project['id'], $userId)]);

            // ── Étape 5 : rédaction ──
            case 'write/start':
                Http::requirePost();
                $project = self::project((int) Http::in('id'), $userId);
                Writer::start($project);
                Http::ok(['status' => Writer::status(self::project((int) $project['id'], $userId))]);

            case 'write/pause':
                Http::requirePost();
                $project = self::project((int) Http::in('id'), $userId);
                Writer::pause($project);
                Http::ok(['status' => Writer::status(self::project((int) $project['id'], $userId))]);

            case 'write/tick':
                Http::requirePost();
                @set_time_limit((int) Config::get('gemini.timeout', 180) + 30);
                $project = self::project((int) Http::in('id'), $userId);
                Http::ok(['status' => Writer::tick($project, self::selectedConceptOrNull($project))]);

            case 'write/status':
                $project = self::project((int) Http::in('id'), $userId);
                Http::ok([
                    'status'  => Writer::status($project),
                    'journal' => Writer::journalSince((int) $project['id'], (int) Http::in('after', 0)),
                ]);

            // ── Étape 6 : chapitres ──
            case 'chapters/get':
                $project = self::project((int) Http::in('id'), $userId);
                Http::ok(ChapterTools::chapter((int) $project['id'], (int) Http::in('num', 1)));

            case 'chapters/action':
                Http::requirePost();
                @set_time_limit((int) Config::get('gemini.timeout', 180) * 3 + 30);
                $project = self::project((int) Http::in('id'), $userId);
                Http::ok(ChapterTools::action(
                    $project,
                    (int) Http::in('num', 1),
                    (string) Http::in('action', ''),
                    (string) Http::in('param', '')
                ));

            // ── Étape 01 : import d'un livre PDF existant ──
            case 'import/analyze':
                // Téléversement + analyse : structure détectée, RIEN n'est encore appliqué
                Http::requirePost();
                @set_time_limit(180);
                $project = self::project((int) ($_POST['id'] ?? 0), $userId);
                $file = $_FILES['file'] ?? null;
                if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    Http::error('Fichier PDF manquant.');
                }
                if (strtolower((string) pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'pdf') {
                    Http::error('Format attendu : PDF.');
                }
                $dir = (string) Config::get('paths.uploads');
                if (!is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }
                $stored = $dir . '/import-' . (int) $project['id'] . '.pdf';
                if (!move_uploaded_file($file['tmp_name'], $stored) && !copy($file['tmp_name'], $stored)) {
                    Http::error('Impossible d\'enregistrer le fichier téléversé.');
                }
                $analysis = \App\Services\BookImport::analyze($stored);
                // Aperçu allégé (le texte intégral reste sur le serveur)
                Http::ok(['analysis' => [
                    'pages'       => $analysis['pages'],
                    'words_total' => $analysis['words_total'],
                    'title_guess' => $analysis['title_guess'],
                    'chapters'    => array_map(fn ($c) => [
                        'title'    => $c['title'],
                        'words'    => $c['words'],
                        'sections' => array_map(fn ($x) => $x['title'], $c['sections']),
                    ], $analysis['chapters']),
                ]]);

            case 'import/apply':
                // Application au projet : « identique » ou « inspire »
                Http::requirePost();
                @set_time_limit(180);
                $project = self::project((int) Http::in('id'), $userId);
                $stored = (string) Config::get('paths.uploads') . '/import-' . (int) $project['id'] . '.pdf';
                if (!is_file($stored)) {
                    Http::error('Aucun PDF analysé pour ce projet — reprenez le téléversement.');
                }
                $mode = Http::in('mode') === 'identique' ? 'identique' : 'inspire';
                if ($mode === 'identique' && !Http::in('owned')) {
                    Http::error('Confirmez que ce livre vous appartient pour le reprendre à l\'identique.');
                }
                $analysis = \App\Services\BookImport::analyze($stored);
                $result = \App\Services\BookImport::apply($project, $analysis, $mode, (string) Http::in('title', ''));
                Http::ok($result + ['project' => self::project((int) $project['id'], $userId)]);

            // ── Sections : édition directe et retouche IA ──
            case 'sections/save':
                Http::requirePost();
                $project = self::project((int) Http::in('id'), $userId);
                $section = self::ownSection((int) $project['id'], (int) Http::in('section_id'));
                $content = trim((string) Http::in('content', ''));
                if (mb_strlen($content) < 20) {
                    Http::error('Contenu trop court pour être enregistré.');
                }
                Db::run(
                    "UPDATE sections SET content = ?, words = ?, status = 'done', updated_at = ? WHERE id = ?",
                    [$content, Util::wordCount($content), Db::now(), (int) $section['id']]
                );
                Util::journal((int) $project['id'], 'ok', 'Section « ' . $section['title'] . ' » modifiée à la main');
                Http::ok(['words' => Util::wordCount($content)]);

            case 'sections/retouch':
                Http::requirePost();
                @set_time_limit(120);
                $project = self::project((int) Http::in('id'), $userId);
                $section = self::ownSection((int) $project['id'], (int) Http::in('section_id'));
                $text = Writer::retouch($project, $section, (string) Http::in('instruction', ''));
                Http::ok(['content' => $text, 'words' => Util::wordCount($text)]);

            // ── Visuels ──
            case 'images/upload':
                self::uploadImage($userId);

            case 'images/generate':
                // Visuel généré par l'IA (nano banana) pour un emplacement du
                // livre, avec prompt libre et regénérations à volonté.
                Http::requirePost();
                @set_time_limit(180);
                $project = self::project((int) Http::in('id'), $userId);
                $imageId = (int) Http::in('image_id');
                $slotRow = Db::one('SELECT * FROM images WHERE id = ? AND project_id = ?', [$imageId, (int) $project['id']]);
                if (!$slotRow) {
                    Http::error('Emplacement visuel introuvable.');
                }
                $chapterRow = Db::one(
                    "SELECT title FROM chapters WHERE project_id = ? AND num = ?",
                    [(int) $project['id'], (int) $slotRow['chapter_num']]
                );
                $style = match ((string) ($project['photo_style'] ?? 'nb')) {
                    'couleur' => 'Photographie professionnelle réaliste en couleurs, lumière naturelle soignée.',
                    'schemas' => 'Schéma pédagogique épuré en flat design, fond blanc, traits nets, sans texte superflu.',
                    default   => 'Photographie professionnelle réaliste en noir et blanc, éclairage travaillé, contrastes riches.',
                };
                $refine = trim((string) Http::in('prompt', ''));
                $prompt = "Illustration intérieure pour un livre pratique intitulé « " . ($project['title'] ?: 'Livre') . " »"
                    . ($chapterRow ? ", chapitre « {$chapterRow['title']} »" : '') . ".\n"
                    . "Sujet du visuel : " . ((string) $slotRow['caption'] !== '' ? $slotRow['caption'] : 'illustration du chapitre') . ".\n"
                    . $style . "\n"
                    . "Cadrage horizontal 3:2, composition claire, adaptée à une impression 300 dpi. Aucun texte incrusté dans l'image."
                    . ($refine !== '' ? "\nExigences précises de l'auteur (prioritaires) : " . $refine : '');
                $binary = \App\Services\Gemini::image($prompt);
                $source = @imagecreatefromstring($binary);
                if (!$source) {
                    Http::error("L'image générée est illisible — relancez.");
                }
                Http::ok(['image' => self::storeSlotImage($project, $slotRow, $source)]);

            // ── Étape 7 : mise en page & publication ──
            case 'layout/summary':
                $project = self::project((int) Http::in('id'), $userId);
                Http::ok(Layout::summary($project, self::selectedConceptOrNull($project)));

            case 'export/pdf':
                @set_time_limit(300);
                $project = self::project((int) Http::in('id'), $userId);
                $book = Layout::bookData($project, self::selectedConceptOrNull($project), $user);
                // Thème choisi (surchargé par ?theme= pour l'aperçu) + accent couverture
                $theme = (string) (Http::in('theme') ?: ($project['interior_theme'] ?? 'editorial'));
                $coverRow = Db::one('SELECT palette FROM covers WHERE project_id = ?', [(int) $project['id']]);
                $coverPalette = $coverRow ? (json_decode((string) $coverRow['palette'], true) ?: []) : [];
                $colors = PdfBook::interiorColors($project, $coverPalette);
                $file = PdfBook::build($project, $book, $theme, $colors['accent'], $colors['ink']);
                self::download($file, Util::slug($book['title']) . '-interieur.pdf', 'application/pdf', (bool) Http::in('inline'));

            case 'interior/themes':
                $project = self::project((int) Http::in('id'), $userId);
                $current = (string) ($project['interior_theme'] ?? 'editorial');
                $coverRow = Db::one('SELECT palette FROM covers WHERE project_id = ?', [(int) $project['id']]);
                $coverPalette = $coverRow ? (json_decode((string) $coverRow['palette'], true) ?: []) : [];
                $colors = PdfBook::interiorColors($project, $coverPalette);
                $accent = $colors['accent'];
                $effective = PdfBook::layoutOptions($project);
                Http::ok([
                    'themes' => array_map(fn ($slug, $meta) => [
                        'slug'     => $slug,
                        'name'     => $meta['name'],
                        'desc'     => $meta['desc'],
                        'thumb'    => \App\Services\InteriorThemes::thumb($slug, $accent, $colors['ink']),
                        'selected' => $slug === $current,
                    ], array_keys(PdfBook::THEMES), PdfBook::THEMES),
                    // Couleurs du livre + palette de la couverture (référence)
                    'colors'        => $colors,
                    'cover_palette' => array_intersect_key($coverPalette, array_flip(['c1', 'c2', 'c3', 'c4'])),
                    // Composeur : ingrédients de mise en page + état coché
                    'options' => array_map(fn ($key, $meta) => [
                        'key'  => $key,
                        'name' => $meta['name'],
                        'desc' => $meta['desc'],
                        'on'   => (bool) $effective[$key],
                    ], array_keys(PdfBook::LAYOUT_OPTIONS), PdfBook::LAYOUT_OPTIONS),
                ]);

            case 'export/epub':
                // eBook Kindle complet : chapitres, encadrés, photos, sommaire
                @set_time_limit(180);
                $project = self::project((int) Http::in('id'), $userId);
                $book = Layout::bookData($project, self::selectedConceptOrNull($project), $user);
                $file = \App\Services\Epub::build($project, $book);
                self::download($file, Util::slug($book['title']) . '.epub', 'application/epub+zip');

            case 'export/docx':
                @set_time_limit(120);
                $project = self::project((int) Http::in('id'), $userId);
                $book = Layout::bookData($project, self::selectedConceptOrNull($project), $user);
                $file = Docx::build($project, $book);
                self::download($file, Util::slug($book['title']) . '-manuscrit.docx',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

            case 'kdpmeta/get':
                $project = self::project((int) Http::in('id'), $userId);
                Http::ok(['meta' => Kdp::meta((int) $project['id'])]);

            case 'kdpmeta/generate':
                Http::requirePost();
                $project = self::project((int) Http::in('id'), $userId);
                Http::ok(['meta' => Kdp::generate($project, self::bookContext($project), $user)]);

            case 'kdpmeta/save':
                Http::requirePost();
                $project = self::project((int) Http::in('id'), $userId);
                Http::ok(['meta' => Kdp::save((int) $project['id'], Http::input())]);

            case 'kdpmeta/aplus':
                // Textes du contenu A+ Amazon (modules sous la description)
                Http::requirePost();
                @set_time_limit(90);
                $project = self::project((int) Http::in('id'), $userId);
                Http::ok(['aplus' => Kdp::generateAplus($project, self::selectedConceptOrNull($project))]);

            case 'kdpmeta/aplus-image':
                // Visuel A+ aux dimensions standard, dans la charte de la couverture
                $project = self::project((int) Http::in('id'), $userId);
                $cover = Covers::get($project, self::selectedConceptOrNull($project), $user);
                $meta = Kdp::meta((int) $project['id']);
                $png = CoverStudio::aplusImage($cover['palette'], $cover['texts'], (string) Http::in('type', 'banner'), (array) ($meta['aplus'] ?? []));
                header('Content-Type: image/png');
                header('Content-Disposition: attachment; filename="aplus-' . (string) Http::in('type', 'banner') . '-' . (int) $project['id'] . '.png"');
                header('Content-Length: ' . strlen($png));
                echo $png;
                exit;

            case 'kdpmeta/mockup':
                // Mockup 3D marketing du livre (face + tranche + ombre)
                @set_time_limit(120);
                $project = self::project((int) Http::in('id'), $userId);
                $cover = Covers::get($project, self::selectedConceptOrNull($project), $user);
                $illus = CoverStudio::illusPath((int) $project['id']);
                $png = CoverStudio::mockup3d(
                    Covers::frontElements($cover, is_file($illus)),
                    $cover['palette'],
                    is_file($illus) ? $illus : null
                );
                header('Content-Type: image/png');
                header('Content-Disposition: attachment; filename="mockup-3d-' . (int) $project['id'] . '.png"');
                header('Content-Length: ' . strlen($png));
                echo $png;
                exit;

            case 'kdpmeta/keyword-ranks':
                // Position de VOTRE ASIN dans la recherche Amazon pour chaque mot-clé
                Http::requirePost();
                @set_time_limit(180);
                $project = self::project((int) Http::in('id'), $userId);
                $meta = Kdp::meta((int) $project['id']);
                $asin = strtoupper(trim((string) $meta['asin']));
                if ($asin === '') {
                    Http::error('Renseignez d\'abord l\'ASIN de votre livre publié (modal Publier).');
                }
                if (!$meta['keywords']) {
                    Http::error('Aucun mot-clé enregistré — générez ou saisissez vos 7 mots-clés.');
                }
                $force = (bool) Http::in('force');
                $ranks = [];
                foreach ($meta['keywords'] as $keyword) {
                    $products = \App\Services\Canopy::search((string) $keyword, 1, $force);
                    $position = null;
                    foreach ((array) ($products ?? []) as $i => $product) {
                        if (strtoupper((string) $product['asin']) === $asin) {
                            $position = $i + 1;
                            break;
                        }
                    }
                    $ranks[] = ['keyword' => $keyword, 'position' => $position, 'scanned' => count((array) ($products ?? []))];
                }
                Http::ok(['ranks' => $ranks, 'asin' => $asin, 'canopy' => \App\Services\Canopy::status()]);

            case 'write/proofread':
                // Relecture d'UN chapitre (le client enchaîne chapitre par chapitre)
                Http::requirePost();
                @set_time_limit(150);
                $project = self::project((int) Http::in('id'), $userId);
                Http::ok(['result' => Writer::proofreadChapter($project, (int) Http::in('chapter_num'))]);

            case 'projects/translate':
                // Version étrangère d'un livre rédigé : l'étape 05 TRADUIT
                Http::requirePost();
                @set_time_limit(120);
                $project = self::project((int) Http::in('id'), $userId);
                $created = \App\Services\Translate::createProject($userId, $project, (string) Http::in('lang', 'en'));
                Http::ok(['id' => $created['id'], 'projects' => Db::all(
                    'SELECT p.*, c.title AS concept_title FROM projects p LEFT JOIN concepts c ON c.id = p.concept_id WHERE p.user_id = ? ORDER BY p.updated_at DESC', [$userId]
                )]);

            case 'cron/info':
                // Écriture autonome : URL + secret pour la tâche cron de l'hébergeur
                $secret = trim((string) \App\Core\Settings::get('cron.secret', ''));
                if ($secret === '') {
                    $secret = bin2hex(random_bytes(20));
                    \App\Core\Settings::set('cron.secret', $secret);
                }
                Http::ok([
                    'url'    => rtrim((string) Config::baseUrl(), '/') . '/cron.php?key=' . $secret,
                    'secret' => $secret,
                ]);

            case 'tokens/list':
                Http::ok(['tokens' => Kdp::tokens($userId)]);

            case 'tokens/create':
                Http::requirePost();
                Http::ok(Kdp::createToken($userId, (string) Http::in('label', '')));

            case 'tokens/revoke':
                Http::requirePost();
                Kdp::revokeToken($userId, (int) Http::in('token_id'));
                Http::ok();

            // ── Connecteurs (clés API éditables depuis l'interface) ──
            case 'connectors/get':
                Http::ok(['connectors' => [
                    'gemini' => [
                        'masked'     => Settings::masked('gemini.api_key'),
                        'source'     => Settings::source('gemini.api_key'),
                        'model_fast' => (string) Settings::get('gemini.model_fast', Config::get('gemini.model_fast')),
                        'model_pro'  => (string) Settings::get('gemini.model_pro', Config::get('gemini.model_pro')),
                    ],
                    'canopy' => [
                        'masked' => Settings::masked('canopy.api_key'),
                        'source' => Settings::source('canopy.api_key'),
                        'domain' => (string) Settings::get('canopy.domain', 'FR'),
                        'status' => Canopy::status(),
                    ],
                ]]);

            case 'connectors/save':
                Http::requirePost();
                $input = Http::input();
                // Clés : champ vide = inchangé ; « - » = effacer la valeur interface
                foreach (['gemini_api_key' => 'gemini.api_key', 'canopy_api_key' => 'canopy.api_key'] as $field => $setting) {
                    if (!array_key_exists($field, $input)) {
                        continue;
                    }
                    $value = trim((string) $input[$field]);
                    if ($value === '-') {
                        Settings::set($setting, '');
                    } elseif ($value !== '') {
                        Settings::set($setting, $value);
                        // Nouvelle clé Canopy : le compteur local repart de zéro
                        if ($setting === 'canopy.api_key') {
                            Canopy::resetUsage();
                        }
                    }
                }
                foreach (['model_fast' => 'gemini.model_fast', 'model_pro' => 'gemini.model_pro', 'canopy_domain' => 'canopy.domain'] as $field => $setting) {
                    if (array_key_exists($field, $input)) {
                        Settings::set($setting, mb_substr(trim((string) $input[$field]), 0, 60));
                    }
                }
                Http::ok(['canopy' => Canopy::status()]);

            case 'canopy/test':
                Http::requirePost();
                // Le diagnostic ne lève jamais : on renvoie toujours 200 avec le détail brut.
                Http::ok(['test' => Canopy::test()]);

            case 'canopy/reset-usage':
                Http::requirePost();
                Canopy::resetUsage();
                Http::ok(['canopy' => Canopy::status()]);

            case 'canopy/calibrate':
                // Cale le compteur local sur le chiffre RÉEL lu par l'utilisateur
                // sur son tableau de bord canopyapi.co.
                Http::requirePost();
                Canopy::setUsage(max(0, (int) Http::in('used', 0)));
                Http::ok(['canopy' => Canopy::status()]);

            case 'gemini/test':
                Http::requirePost();
                // Échec rapide : pas de relance, timeout court, budget large
                // (les modèles « réflexifs » consomment des jetons avant de répondre)
                $reply = Gemini::text('Réponds uniquement le mot : OK', [
                    'model' => 'fast', 'temperature' => 0, 'max_tokens' => 2048,
                    'retries' => 0, 'timeout' => 45,
                ]);
                Http::ok(['reply' => mb_substr(trim($reply), 0, 60)]);

            case 'gemini/models':
                try {
                    Http::ok(['models' => Gemini::models()]);
                } catch (\Throwable $e) {
                    Http::ok(['models' => []]);
                }

            // ── Veille marché (tableau de bord Canopy, relevés au clic) ──
            case 'watch/list':
                Http::ok(['watches' => Watch::list($userId), 'canopy' => Canopy::status()]);

            case 'watch/add':
                Http::requirePost();
                Watch::add($userId, (string) Http::in('term', ''));
                Http::ok(['watches' => Watch::list($userId)]);

            case 'watch/remove':
                Http::requirePost();
                Watch::remove($userId, (int) Http::in('watch_id'));
                Http::ok(['watches' => Watch::list($userId)]);

            case 'watch/refresh':
                Http::requirePost();
                $result = Watch::refresh($userId, (int) Http::in('watch_id'));
                Http::ok(['watches' => Watch::list($userId), 'canopy' => $result['status']]);

            case 'watch/book':
                Http::requirePost();
                $newId = Watch::createBook($userId, (int) Http::in('watch_id'));
                Http::ok(['project' => self::project($newId, $userId)]);
        }
    }

    // ── Routes à jeton pour le userscript (CORS) ───────────────────────────

    private static function kdpTokenRoutes(string $route): void
    {
        Http::cors();
        $user = Auth::userByToken((string) ($_GET['token'] ?? ''));
        if (!$user) {
            Http::error('Jeton d\'API invalide.', 401);
        }
        $userId = (int) $user['id'];

        if ($route === 'kdp/projects') {
            $projects = Db::all(
                "SELECT p.id, p.title, p.writing_status, p.updated_at FROM projects p
                 WHERE p.user_id = ? ORDER BY p.updated_at DESC LIMIT 50", [$userId]
            );
            Http::ok(['projects' => $projects]);
        }
        if ($route === 'kdp/payload') {
            $project = self::project((int) ($_GET['project'] ?? 0), $userId);
            Http::ok(['payload' => Kdp::payload($project, self::selectedConceptOrNull($project))]);
        }
        Http::error('Route inconnue.', 404);
    }

    // ── Aides ──────────────────────────────────────────────────────────────

    private static function project(int $id, int $userId): array
    {
        \App\Core\Migrations::run();
        $project = Db::one('SELECT * FROM projects WHERE id = ? AND user_id = ?', [$id, $userId]);
        if (!$project) {
            Http::error('Projet introuvable.', 404);
        }
        return $project;
    }

    private static function projectBundle(int $id, int $userId): array
    {
        $project = self::project($id, $userId);
        $projectId = (int) $project['id'];
        return [
            'project'  => $project,
            'themes'   => [
                'analysis' => Market::listFor($projectId, 'analysis'),
                'trends'   => Market::listFor($projectId, 'trends'),
            ],
            'concepts' => Concepts::listFor($projectId),
            'toc'      => Toc::draft($project),
            'trims'    => Config::get('trims'),
        ];
    }

    private static function updateProject(int $id, int $userId): void
    {
        $project = self::project($id, $userId);
        $input = Http::input();
        $fields = [];
        $params = [];

        $map = [
            'title'       => fn ($v) => mb_substr(trim((string) $v), 0, 250),
            'step'        => fn ($v) => max(1, min(7, (int) $v)),
            'mode'        => fn ($v) => in_array($v, ['describe', 'trends', 'import'], true) ? $v : 'describe',
            'idea'        => fn ($v) => (string) $v,
            'pages'       => fn ($v) => max(60, min(400, (int) $v)),
            'final_pages' => fn ($v) => ($v === '' || $v === null || (int) $v <= 0) ? null : max(24, min(828, (int) $v)),
            'photos'      => fn ($v) => $v ? 1 : 0,
            'photos_per'  => fn ($v) => max(1, min(6, (int) $v)),
            'photo_style' => fn ($v) => in_array($v, ['nb', 'couleur', 'schemas'], true) ? $v : 'nb',
            'tone'        => fn ($v) => mb_substr(trim((string) $v), 0, 50),
            'trim_format' => fn ($v) => Config::get('trims.' . $v) ? $v : '6x9',
            'interior_theme' => fn ($v) => isset(PdfBook::THEMES[$v]) ? $v : 'editorial',
            'interior_colors' => function ($v) {
                // '' ou null = revenir aux couleurs de la couverture
                if (!is_array($v)) {
                    return null;
                }
                $hex = fn ($x) => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $x) ? (string) $x : null;
                $clean = array_filter([
                    'accent' => $hex($v['accent'] ?? null),
                    'ink'    => $hex($v['ink'] ?? null),
                ]);
                return $clean ? json_encode($clean) : null;
            },
            'layout_options' => function ($v) {
                $clean = [];
                foreach (array_keys(PdfBook::LAYOUT_OPTIONS) as $key) {
                    if (is_array($v) && array_key_exists($key, $v)) {
                        $clean[$key] = (bool) $v[$key];
                    }
                }
                return json_encode($clean);
            },
        ];
        foreach ($map as $key => $clean) {
            if (array_key_exists($key, $input)) {
                $fields[] = "$key = ?";
                $params[] = $clean($input[$key]);
            }
        }
        if (!$fields) {
            return;
        }
        $params[] = Db::now();
        $params[] = $project['id'];
        Db::run('UPDATE projects SET ' . implode(', ', $fields) . ', updated_at = ? WHERE id = ?', $params);
    }

    private static function selectedTheme(array $project): array
    {
        $theme = $project['theme_id']
            ? Db::one('SELECT * FROM themes WHERE id = ? AND project_id = ?', [(int) $project['theme_id'], (int) $project['id']])
            : null;
        if (!$theme) {
            Http::error('Sélectionnez d\'abord une thématique (étape 1).');
        }
        return $theme;
    }

    private static function selectedConcept(array $project): array
    {
        $concept = self::selectedConceptOrNull($project);
        if (!$concept) {
            Http::error('Sélectionnez d\'abord un livre (étape 2).');
        }
        return $concept;
    }

    /**
     * Contexte éditorial pour les prompts IA : le concept choisi à l'étape 02
     * s'il existe, SINON reconstitué à partir du livre lui-même (titre,
     * couverture, plan et premières lignes). Indispensable aux livres importés
     * ou créés sans passer par la sélection de concept : la génération des
     * textes de couverture et des métadonnées KDP fonctionne dans tous les cas.
     */
    private static function bookContext(array $project): array
    {
        $concept = self::selectedConceptOrNull($project);
        if ($concept) {
            return $concept;
        }
        $projectId = (int) $project['id'];
        $coverRow = Db::one('SELECT texts FROM covers WHERE project_id = ?', [$projectId]);
        $texts = $coverRow ? (json_decode((string) $coverRow['texts'], true) ?: []) : [];

        $chapters = Db::all(
            "SELECT title FROM chapters WHERE project_id = ? AND role = 'chapter' ORDER BY num LIMIT 20",
            [$projectId]
        );
        $first = Db::one(
            "SELECT s.content FROM sections s JOIN chapters c ON c.id = s.chapter_id
             WHERE c.project_id = ? AND s.content IS NOT NULL AND s.content != '' ORDER BY c.num, s.num LIMIT 1",
            [$projectId]
        );

        $title = trim((string) ($texts['title'] ?? '')) ?: (string) $project['title'];
        $description = trim((string) ($texts['back_text'] ?? ''));
        if ($description === '') {
            $description = trim((string) $project['idea']);
        }
        if ($description === '' && $first) {
            $description = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $first['content']) ?? ''), 0, 500);
        }
        if ($chapters) {
            $description .= "\nPlan du livre : " . implode(' · ', array_column($chapters, 'title'));
        }
        $summary = Layout::summary($project, null);

        return [
            'id'          => null,
            'title'       => $title,
            'short_title' => mb_substr($title, 0, 120),
            'hook'        => trim((string) ($texts['tagline'] ?? '')),
            'description' => trim($description) !== '' ? trim($description) : ('Livre pratique : ' . $title),
            'badge'       => '',
            'competition' => 'Moyenne',
            'price'       => number_format((float) ($summary['pricing']['price'] ?? 14.9), 2, ',', ' ') . ' €',
        ];
    }

    /** Section appartenant bien au projet (avec contexte chapitre). */
    private static function ownSection(int $projectId, int $sectionId): array
    {
        $section = Db::one(
            "SELECT s.*, c.title AS chapter_title, c.num AS chapter_num, c.role AS chapter_role
             FROM sections s JOIN chapters c ON c.id = s.chapter_id
             WHERE s.id = ? AND c.project_id = ?",
            [$sectionId, $projectId]
        );
        if (!$section) {
            Http::error('Section introuvable.');
        }
        return $section;
    }

    private static function selectedConceptOrNull(array $project): ?array
    {
        return $project['concept_id']
            ? Db::one('SELECT * FROM concepts WHERE id = ? AND project_id = ?', [(int) $project['concept_id'], (int) $project['id']])
            : null;
    }

    private static function uploadImage(int $userId): void
    {
        Http::requirePost();
        $project = self::project((int) ($_POST['id'] ?? 0), $userId);
        $imageId = (int) ($_POST['image_id'] ?? 0);
        $slotRow = Db::one('SELECT * FROM images WHERE id = ? AND project_id = ?', [$imageId, (int) $project['id']]);
        if (!$slotRow) {
            Http::error('Emplacement visuel introuvable.');
        }
        $file = $_FILES['file'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Http::error('Fichier manquant ou refusé par le serveur.');
        }
        $info = @getimagesize($file['tmp_name']);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            Http::error('Format accepté : JPEG, PNG ou WebP.');
        }

        $source = match ($info[2]) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($file['tmp_name']),
            IMAGETYPE_PNG  => imagecreatefrompng($file['tmp_name']),
            IMAGETYPE_WEBP => imagecreatefromwebp($file['tmp_name']),
        };
        if (!$source) {
            Http::error('Image illisible.');
        }
        Http::ok(['image' => self::storeSlotImage($project, $slotRow, $source)]);
    }

    /**
     * Enregistre un visuel dans son emplacement : recadrage central au format
     * 3:2 de la maquette (aucune déformation dans le PDF), conversion JPEG,
     * niveaux de gris si le style choisi à l'étape 03 n'est pas « couleur ».
     */
    private static function storeSlotImage(array $project, array $slotRow, \GdImage $source): array
    {
        $dir = (string) Config::get('paths.uploads');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $sw = imagesx($source);
        $sh = imagesy($source);
        // Recadrage central au ratio 3:2 (celui des emplacements de la maquette)
        $targetRatio = 3 / 2;
        if ($sw / $sh > $targetRatio) {
            $cw = (int) round($sh * $targetRatio);
            $ch = $sh;
            $cx = (int) (($sw - $cw) / 2);
            $cy = 0;
        } else {
            $cw = $sw;
            $ch = (int) round($sw / $targetRatio);
            $cx = 0;
            $cy = (int) (($sh - $ch) / 2);
        }
        $canvas = imagecreatetruecolor($cw, $ch);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $source, 0, 0, $cx, $cy, $cw, $ch);
        // Style visuel choisi à l'étape 03 : N&B et schémas passent en niveaux
        // de gris (fidèle à la spec annoncée et à l'impression KDP encre noire)
        if (($project['photo_style'] ?? 'nb') !== 'couleur') {
            imagefilter($canvas, IMG_FILTER_GRAYSCALE);
        }
        $name = 'p' . $project['id'] . '-img' . (int) $slotRow['id'] . '-' . substr(bin2hex(random_bytes(6)), 0, 8) . '.jpg';
        imagejpeg($canvas, $dir . '/' . $name, 92);
        imagedestroy($source);
        imagedestroy($canvas);

        if (!empty($slotRow['filename']) && is_file($dir . '/' . $slotRow['filename'])) {
            @unlink($dir . '/' . $slotRow['filename']);
        }
        Db::run('UPDATE images SET filename = ?, width = ?, height = ? WHERE id = ?', [$name, $cw, $ch, (int) $slotRow['id']]);
        return Db::one('SELECT * FROM images WHERE id = ?', [(int) $slotRow['id']]) ?: $slotRow;
    }

    private static function download(string $file, string $downloadName, string $mime, bool $inline = false): never
    {
        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $downloadName . '"');
        header('Content-Length: ' . (string) filesize($file));
        header('Cache-Control: no-store');
        readfile($file);
        exit;
    }

    private static function publicUser(array $user): array
    {
        return [
            'id'           => (int) $user['id'],
            'email'        => $user['email'],
            'display_name' => $user['display_name'],
            'initials'     => self::initials($user['display_name'] ?: $user['email']),
        ];
    }

    private static function initials(string $name): string
    {
        $parts = preg_split('/[\s.@_-]+/u', trim($name)) ?: [];
        $initials = '';
        foreach ($parts as $part) {
            if ($part !== '' && mb_strlen($initials) < 2) {
                $initials .= mb_strtoupper(mb_substr($part, 0, 1));
            }
        }
        return $initials ?: 'AU';
    }
}
