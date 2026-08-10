<?php
declare(strict_types=1);

/**
 * Couverture complète broché : 4ème + dos + 1ère, fond perdu compris,
 * aux dimensions exactes KDP. Imprimez en PDF (marges « Aucune », 100 %).
 */
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;
use App\Core\Db;
use App\Services\Covers;
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
$concept = $project['concept_id'] ? Db::one('SELECT * FROM concepts WHERE id = ?', [(int) $project['concept_id']]) : null;

$cover = Covers::get($project, $concept, $user);
$geometry = Layout::geometry($project);

$bleed = (float) $geometry['bleed_mm'];
$trimW = (float) $geometry['w_mm'];
$trimH = (float) $geometry['h_mm'];
$spine = (float) $geometry['spine_mm'];
$totalW = $bleed + $trimW + $spine + $trimW + $bleed;
$totalH = $trimH + 2 * $bleed;
$spineText = $spine >= 6.35; // KDP : pas de texte de dos sous ~100 pages

$injectSize = function (string $svg, float $wMm, float $hMm): string {
    return preg_replace(
        '/<svg\b/',
        '<svg style="width:' . $wMm . 'mm;height:' . $hMm . 'mm;display:block;" preserveAspectRatio="xMidYMid slice"',
        $svg,
        1
    ) ?? $svg;
};
// Les faces débordent dans le fond perdu (haut/bas/extérieur)
$faceW = $trimW + $bleed;
$faceH = $trimH + 2 * $bleed;
$backSvg = $injectSize(Covers::render($cover, 'back'), $faceW, $faceH);

// 1ère de couverture : rendu studio (raster haute résolution) si choisi,
// sinon gabarit SVG historique.
if (($cover['template'] ?? '') === 'studio') {
    $frontSvg = '<img src="api.php?r=coverstudio/front&id=' . (int) $project['id'] . '&t=' . time()
        . '" alt="" style="width:' . $faceW . 'mm;height:' . $faceH . 'mm;object-fit:cover;display:block;">';
} else {
    $frontSvg = $injectSize(Covers::render($cover, 'front'), $faceW, $faceH);
}

$palette = $cover['palette'];
$texts = $cover['texts'];
$barcodeW = (float) Config::get('kdp.barcode_w_mm', 50.8);
$barcodeH = (float) Config::get('kdp.barcode_h_mm', 30.5);
$e = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Couverture — <?= $e((string) ($texts['title'] ?? '')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=Instrument+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body { background: #E8E1D3; font-family: 'Instrument Sans', sans-serif; }
.toolbar {
  position: fixed; top: 0; left: 0; right: 0; z-index: 10; display: flex; align-items: center;
  justify-content: space-between; padding: 10px 18px; background: rgba(244,239,228,.94);
  border-bottom: 1px solid #E2D9C7; font-size: 13px; backdrop-filter: blur(10px);
}
.toolbar .btn { padding: 8px 14px; border-radius: 8px; background: #1B2A4A; color: #F7F2E7; cursor: pointer; border: none; font-size: 13px; font-family: inherit; }
.toolbar .hint { color: #6E685C; }
.stage { padding: 80px 20px 60px; display: grid; place-items: center; }
.wrap {
  position: relative; width: <?= $totalW ?>mm; height: <?= $totalH ?>mm;
  background: <?= $e((string) ($palette['c1'] ?? '#1B2A4A')) ?>;
  box-shadow: 0 18px 44px rgba(48,40,26,.3); overflow: hidden;
}
.panel { position: absolute; top: 0; height: <?= $faceH ?>mm; overflow: hidden; }
.panel.back  { left: 0; width: <?= $faceW ?>mm; }
.panel.front { right: 0; width: <?= $faceW ?>mm; }
.spine {
  position: absolute; top: 0; left: <?= $bleed + $trimW ?>mm; width: <?= $spine ?>mm; height: 100%;
  background: <?= $e((string) ($palette['c1'] ?? '#1B2A4A')) ?>;
  display: flex; align-items: center; justify-content: center; overflow: hidden;
}
.spine .txt {
  transform: rotate(90deg); white-space: nowrap; color: <?= $e((string) ($palette['c3'] ?? '#F4EFE4')) ?>;
  font-family: 'Instrument Serif', serif; font-size: <?= max(7, min(13, $spine * 0.42)) ?>pt; letter-spacing: .06em;
}
.spine .txt .author { font-family: 'Instrument Sans', sans-serif; font-size: .72em; letter-spacing: .18em; text-transform: uppercase; opacity: .8; margin-left: 2.2em; }
.barcode-zone {
  position: absolute; bottom: <?= $bleed + 6.35 ?>mm; left: <?= $bleed + $trimW - 6.35 - $barcodeW ?>mm;
  width: <?= $barcodeW ?>mm; height: <?= $barcodeH ?>mm; background: #FFFFFF;
}
.guide { position: absolute; inset: <?= $bleed ?>mm; border: .2mm dashed rgba(255,255,255,.55); pointer-events: none; }
.caption { text-align: center; color: #7E7768; font-size: 12.5px; margin-top: 16px; }
@media print {
  body { background: #FFF; }
  .toolbar, .caption { display: none; }
  .stage { padding: 0; }
  .wrap { box-shadow: none; }
  .guide { display: none; }
  @page { size: <?= $totalW ?>mm <?= $totalH ?>mm; margin: 0; }
}
</style>
</head>
<body>
<div class="toolbar">
  <div class="hint">Couverture complète · <?= str_replace('.', ',', (string) $totalW) ?> × <?= str_replace('.', ',', (string) $totalH) ?> mm (fond perdu <?= str_replace('.', ',', (string) $bleed) ?> mm compris) · dos <?= str_replace('.', ',', (string) $spine) ?> mm</div>
  <button class="btn" onclick="window.print()">Imprimer / Enregistrer en PDF</button>
</div>
<div class="stage">
  <div>
    <div class="wrap">
      <div class="panel back"><?= $backSvg ?></div>
      <div class="spine"><?php if ($spineText): ?><div class="txt"><?= $e((string) ($texts['title'] ?? '')) ?><span class="author"><?= $e((string) ($texts['author'] ?? '')) ?></span></div><?php endif; ?></div>
      <div class="panel front"><?= $frontSvg ?></div>
      <div class="barcode-zone" title="Zone code-barres réservée par KDP"></div>
      <div class="guide"></div>
    </div>
    <div class="caption">Le trait pointillé matérialise la coupe (invisible à l'impression). La zone blanche en bas de la 4ème est réservée au code-barres apposé par KDP.</div>
  </div>
</div>
</body>
</html>
