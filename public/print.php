<?php
declare(strict_types=1);

/**
 * Épreuve intérieure paginée — mise en page professionnelle du livre.
 *
 * Modes :
 *   print.php?id=N            → toutes les pages, prêtes à « Imprimer > PDF »
 *   print.php?id=N&auto=1     → idem + boîte de dialogue d'impression auto
 *   print.php?id=N&mode=preview → aperçu double page navigable (iframe étape 7)
 *
 * La pagination est faite par paginate.js : pages aux dimensions exactes du
 * format choisi, marges en miroir avec gouttière KDP, ouvertures de chapitre
 * sur belle page avec lettrine, titres courants, folios, sommaire paginé.
 */
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Db;
use App\Services\Layout;

$user = Auth::user();
if (!$user) {
    http_response_code(401);
    exit('Authentification requise — connectez-vous puis rechargez.');
}
$project = Db::one('SELECT * FROM projects WHERE id = ? AND user_id = ?', [(int) ($_GET['id'] ?? 0), (int) $user['id']]);
if (!$project) {
    http_response_code(404);
    exit('Projet introuvable.');
}
$concept = $project['concept_id']
    ? Db::one('SELECT * FROM concepts WHERE id = ?', [(int) $project['concept_id']])
    : null;

$book = Layout::bookData($project, $concept, $user);
$geometry = Layout::geometry($project);
$mode = (string) ($_GET['mode'] ?? 'full');

// URL des images uploadées, indexées par id
foreach ($book['chapters'] as &$chapterRef) {
    foreach ($chapterRef['images'] as &$imageRef) {
        $imageRef['url'] = !empty($imageRef['filename']) ? 'media.php?img=' . (int) $imageRef['id'] : null;
    }
}
unset($chapterRef, $imageRef);
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Épreuve — <?= htmlspecialchars($book['title']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=Instrument+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root {
  --page-w: <?= $geometry['w_mm'] ?>mm;
  --page-h: <?= $geometry['h_mm'] ?>mm;
  --m-top: <?= $geometry['margin_top_mm'] ?>mm;
  --m-bottom: <?= $geometry['margin_bottom_mm'] ?>mm;
  --m-inner: <?= $geometry['margin_inner_mm'] ?>mm;
  --m-outer: <?= $geometry['margin_outer_mm'] ?>mm;
}
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body { background: #E8E1D3; font-family: 'Instrument Serif', Georgia, serif; }

.sheet {
  width: var(--page-w); height: var(--page-h); background: #FFFDF7; position: relative;
  overflow: hidden; margin: 0 auto 10mm; box-shadow: 0 6px 24px rgba(48,40,26,.18);
}
.sheet .content {
  position: absolute; top: var(--m-top); bottom: var(--m-bottom);
  overflow: hidden;
}
.sheet.recto .content { left: var(--m-inner); right: var(--m-outer); }
.sheet.verso .content { left: var(--m-outer); right: var(--m-inner); }
.running-head {
  position: absolute; top: calc(var(--m-top) - 9mm); font-family: 'Instrument Serif', serif;
  font-size: 8.5pt; letter-spacing: .16em; text-transform: uppercase; color: #9C9382; white-space: nowrap;
}
.sheet.recto .running-head { right: var(--m-outer); text-align: right; }
.sheet.verso .running-head { left: var(--m-outer); }
.folio {
  position: absolute; bottom: calc(var(--m-bottom) - 8mm); font-family: 'Instrument Serif', serif;
  font-size: 9pt; color: #9C9382;
}
.sheet.recto .folio { right: var(--m-outer); }
.sheet.verso .folio { left: var(--m-outer); }

/* Typographie du livre */
.prose p {
  font-family: 'Instrument Serif', serif; font-size: 11.2pt; line-height: 1.42;
  color: #221F19; text-align: justify; hyphens: auto; margin: 0 0 4.2pt; orphans: 2; widows: 2;
}
.prose p.indent { text-indent: 5mm; }
.prose p.dropcap::first-letter {
  float: left; font-size: 30pt; line-height: .84; padding: 1pt 4pt 0 0; color: #221F19;
}
h2.section-title {
  font-family: 'Instrument Serif', serif; font-size: 13.5pt; margin: 9pt 0 5pt; font-weight: normal;
}
.chapter-opening .chapter-word {
  font-family: 'IBM Plex Mono', monospace; font-size: 7.5pt; letter-spacing: .2em;
  text-transform: uppercase; color: #C4571F; margin: 14mm 0 4mm;
}
.chapter-opening h1 {
  font-family: 'Instrument Serif', serif; font-size: 23pt; line-height: 1.12;
  margin: 0 0 9mm; font-weight: normal; letter-spacing: -.01em;
}
figure.book-figure { margin: 6pt 0 8pt; }
figure.book-figure .frame {
  width: 100%; aspect-ratio: 3/2; border: .3pt dashed #CFC4AC; background: #EFE8DA;
  display: grid; place-items: center; text-align: center; padding: 5mm; overflow: hidden;
}
figure.book-figure .frame img { width: 100%; height: 100%; object-fit: cover; display: block; }
figure.book-figure .frame .ph { font-family: 'Instrument Sans', sans-serif; font-size: 8pt; color: #6E685C; }
figure.book-figure figcaption { font-family: 'Instrument Serif', serif; font-style: italic; font-size: 8.5pt; color: #6E685C; margin-top: 2mm; }

/* Pages liminaires */
.front-center { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 0 14mm; }
.front-center .half-title { font-family: 'Instrument Serif', serif; font-size: 13pt; }
.front-center .book-title { font-family: 'Instrument Serif', serif; font-size: 24pt; line-height: 1.12; letter-spacing: -.01em; }
.front-center .book-subtitle { font-family: 'Instrument Serif', serif; font-style: italic; font-size: 12pt; color: #55503F; margin-top: 6mm; max-width: 80%; }
.front-center .book-author { font-family: 'Instrument Sans', sans-serif; font-size: 10.5pt; letter-spacing: .14em; text-transform: uppercase; margin-top: 14mm; }
.copyright-block { position: absolute; left: var(--m-outer); right: var(--m-inner); bottom: var(--m-bottom); font-family: 'Instrument Sans', sans-serif; font-size: 7.5pt; line-height: 1.7; color: #55503F; }
.toc-title { font-family: 'Instrument Serif', serif; font-size: 18pt; text-align: center; margin: 8mm 0 10mm; }
.toc-line { display: flex; justify-content: space-between; align-items: baseline; gap: 4mm; font-family: 'Instrument Serif', serif; font-size: 10.5pt; padding: 2.4mm 0; border-bottom: .3pt solid #E5DCC8; }
.toc-line .pg { font-variant-numeric: tabular-nums; color: #55503F; }

/* Barre d'outils écran */
.toolbar {
  position: fixed; top: 0; left: 0; right: 0; z-index: 10; display: flex; align-items: center;
  justify-content: space-between; padding: 10px 18px; background: rgba(244,239,228,.94);
  backdrop-filter: blur(10px); border-bottom: 1px solid #E2D9C7; font-family: 'Instrument Sans', sans-serif; font-size: 13px;
}
.toolbar .btn { padding: 8px 14px; border-radius: 8px; background: #1B2A4A; color: #F7F2E7; cursor: pointer; border: none; font-size: 13px; font-family: inherit; }
.toolbar .hint { color: #6E685C; }
body.full { padding-top: 60px; }
body.full .pages-flow { padding: 16px 0 60px; }

/* Mode aperçu (iframe) : double page */
body.preview { overflow: hidden; background: transparent; }
.spread-viewport { display: flex; flex-direction: column; align-items: center; gap: 14px; padding: 8px 0 16px; }
.spread { display: flex; gap: 1mm; justify-content: center; filter: drop-shadow(0 18px 34px rgba(48,40,26,.22)); transform-origin: top center; }
.spread .sheet { margin: 0; box-shadow: none; }
.spread-nav { display: flex; align-items: center; gap: 16px; font-family: 'Instrument Sans', sans-serif; font-size: 13px; color: #6E685C; }
.spread-nav button { padding: 7px 13px; border-radius: 7px; border: 1px solid #D9CFBB; background: #FFFDF8; cursor: pointer; font-family: inherit; font-size: 12.5px; }
.spread-nav button:hover { border-color: #1B2A4A; }

@media print {
  body { background: #FFFFFF; padding: 0 !important; }
  .toolbar, .spread-nav { display: none !important; }
  .pages-flow { padding: 0 !important; }
  .sheet { margin: 0; box-shadow: none; page-break-after: always; }
  @page { size: <?= $geometry['w_mm'] ?>mm <?= $geometry['h_mm'] ?>mm; margin: 0; }
}
</style>
</head>
<body class="<?= $mode === 'preview' ? 'preview' : 'full' ?>">

<?php if ($mode !== 'preview'): ?>
<div class="toolbar">
  <div class="hint">Épreuve intérieure · <span id="page-count">…</span> pages · Imprimez en PDF au format exact (marges : « Aucune », échelle 100 %).</div>
  <button class="btn" onclick="window.print()">Imprimer / Enregistrer en PDF</button>
</div>
<?php endif; ?>

<div id="flow" class="pages-flow"></div>

<script>
window.BOOK = <?= json_encode($book, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.GEO = <?= json_encode($geometry, JSON_UNESCAPED_UNICODE) ?>;
window.MODE = <?= json_encode($mode) ?>;
window.AUTO_PRINT = <?= !empty($_GET['auto']) ? 'true' : 'false' ?>;
</script>
<script src="assets/js/paginate.js"></script>
</body>
</html>
