<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * IMPORT D'UNE COUVERTURE COMPLÈTE (« planche »).
 *
 * Sur Amazon KDP, la couverture d'un broché est UN SEUL fichier qui contient,
 * de gauche à droite : 4ème de couverture · dos (tranche) · 1ère de couverture,
 * plus 3,175 mm de fond perdu tout autour. C'est cette planche que l'auteur
 * fabrique dans Photoshop, Canva ou InDesign — et c'est elle qu'on importe ici,
 * en PDF ou en image.
 *
 * Ce service sait :
 *   - lire les dimensions d'une planche (PDF ou image) ;
 *   - en déduire le format du livre, l'épaisseur du dos et donc la pagination
 *     qu'elle suppose, puis la CONFRONTER aux réglages du projet (c'est le
 *     contrôle qui évite un refus de KDP au téléversement) ;
 *   - découper une planche image en ses trois panneaux ;
 *   - dire si un PDF peut être aplati en image de façon FIABLE — beaucoup de
 *     PDF Photoshop peignent leur texte à travers un chemin de découpe, et
 *     aucun aperçu honnête ne peut en être tiré sans moteur de rendu.
 */
final class CoverImport
{
    /** Tolérance d'appariement d'un format d'impression (mm). */
    private const TRIM_TOLERANCE = 2.0;

    // ── Lecture des dimensions ─────────────────────────────────────────────

    /**
     * Dimensions de la planche + diagnostic d'aperçu.
     * @return array{w_mm:float,h_mm:float,source:string,pages:int,flat:bool,reason:string}|null
     */
    public static function readPdf(string $file): ?array
    {
        $raw = (string) @file_get_contents($file);
        if ($raw === '' || !str_starts_with($raw, '%PDF')) {
            return null;
        }
        // MediaBox de la première page (à défaut, la première rencontrée)
        if (!preg_match('/MediaBox\s*\[\s*([\d.\-]+)\s+([\d.\-]+)\s+([\d.\-]+)\s+([\d.\-]+)/', $raw, $m)) {
            return null;
        }
        $wPt = (float) $m[3] - (float) $m[1];
        $hPt = (float) $m[4] - (float) $m[2];
        if ($wPt <= 0 || $hPt <= 0) {
            return null;
        }
        [$flat, $reason] = self::flattenable($raw);

        return [
            'w_mm'   => round($wPt * 25.4 / 72, 1),
            'h_mm'   => round($hPt * 25.4 / 72, 1),
            'source' => 'pdf',
            'pages'  => max(1, substr_count($raw, '/Type/Page') - substr_count($raw, '/Type/Pages')),
            'flat'   => $flat,
            'reason' => $reason,
        ];
    }

    /**
     * Un aperçu image peut-il être extrait FIDÈLEMENT de ce PDF ?
     *
     * Oui uniquement dans le cas simple : une seule image pleine page, posée
     * telle quelle (export « aplati » de Canva, BookBrush, InDesign…).
     * Non dès que le fichier empile plusieurs calques ou peint son texte à
     * travers un chemin de découpe (mode de rendu 7), comme le fait Photoshop :
     * un aperçu extrait serait FAUX, mieux vaut l'annoncer que l'afficher.
     *
     * @return array{0:bool,1:string}
     */
    private static function flattenable(string $raw): array
    {
        $images = preg_match_all('~/Subtype\s*/Image~', $raw);
        if ($images === 0) {
            return [false, 'Cette couverture est vectorielle : aucune image à en extraire.'];
        }
        if (str_contains($raw, '7 Tr') || str_contains($raw, '7 Tr\n')) {
            return [false, 'Le texte de cette couverture est peint à travers un masque (export Photoshop).'];
        }
        if ($images > 1) {
            return [false, 'Cette couverture est composée de ' . $images . ' calques superposés.'];
        }
        return [true, ''];
    }

    /**
     * Dimensions d'une planche fournie en IMAGE.
     *
     * Une image ne porte pas de dimension physique fiable : on ne suppose donc
     * PAS 300 dpi. On cherche plutôt le format KDP dont les PROPORTIONS
     * collent à celles de l'image — pour chaque format, l'épaisseur de dos qui
     * expliquerait exactement ce rapport largeur/hauteur — et on ne retient
     * que les combinaisons plausibles (dos réaliste, résolution entre 100 et
     * 900 dpi). À défaut, on retombe sur une lecture à 300 dpi.
     *
     * @return array{w_mm:float,h_mm:float,source:string,pages:int,flat:bool,reason:string,dpi:int}|null
     */
    public static function readImage(string $file, ?int $forceDpi = null, string $preferTrim = ''): ?array
    {
        $info = @getimagesize($file);
        if (!$info || $info[0] < 200) {
            return null;
        }
        [$pxW, $pxH] = $info;
        $base = ['source' => 'image', 'pages' => 1, 'flat' => true, 'reason' => ''];

        if ($forceDpi === null) {
            $bleed = (float) Config::get('kdp.bleed_mm', 3.175);
            $perPage = (float) Config::get('kdp.spine_per_page', 0.0572);
            $maxSpine = 828 * $perPage;          // KDP plafonne le broché à 828 pages
            $ratio = $pxW / max(1, $pxH);
            $best = null;
            foreach ((array) Config::get('trims', []) as $key => $trim) {
                $hMm = (float) $trim['h_mm'] + 2 * $bleed;
                $wMm = $ratio * $hMm;
                $spine = $wMm - 2 * (float) $trim['w_mm'] - 2 * $bleed;
                if ($spine < 0.5 || $spine > 70) {
                    continue;
                }
                $dpi = $pxH / ($hMm / 25.4);
                if ($dpi < 100 || $dpi > 900) {
                    continue;
                }
                // Départage, du plus décisif au moins décisif : le format du
                // livre en cours (vous téléversez la couverture DE CE livre),
                // un dos imprimable par KDP, puis une résolution proche de 300 dpi.
                $score = abs($dpi - 300)
                    + ($key === $preferTrim ? 0 : 400)
                    + ($spine > $maxSpine ? 5000 : 0);
                if ($best === null || $score < $best['score']) {
                    $best = ['score' => $score, 'w_mm' => $wMm, 'h_mm' => $hMm, 'dpi' => (int) round($dpi)];
                }
            }
            if ($best) {
                return $base + ['w_mm' => round($best['w_mm'], 1), 'h_mm' => round($best['h_mm'], 1), 'dpi' => $best['dpi']];
            }
        }

        $dpi = $forceDpi ?: 300;
        return $base + [
            'w_mm' => round($pxW * 25.4 / $dpi, 1),
            'h_mm' => round($pxH * 25.4 / $dpi, 1),
            'dpi'  => $dpi,
        ];
    }

    // ── Géométrie : que raconte cette planche ? ────────────────────────────

    /**
     * Déduit le format du livre, l'épaisseur du dos et la pagination qu'implique
     * une planche, puis compare aux réglages du projet.
     *
     * @param array $size   sortie de readPdf()/readImage()
     * @param array $project projet courant (trim_format, final_pages…)
     */
    public static function diagnose(array $size, array $project, int $projectPages): array
    {
        $bleed = (float) Config::get('kdp.bleed_mm', 3.175);
        $perPage = (float) Config::get('kdp.spine_per_page', 0.0572);
        $trims = (array) Config::get('trims', []);

        // Hauteur de la planche = hauteur du livre + 2 fonds perdus
        $trimH = $size['h_mm'] - 2 * $bleed;

        // Plusieurs formats KDP partagent la même hauteur (7×10 et 8×10, par
        // exemple) : on retient donc TOUS les candidats compatibles, puis on
        // départage sur le dos — d'abord imprimable par KDP (≤ 828 pages), et
        // au plus près de celui qu'implique la pagination du livre.
        $maxSpine = 828 * $perPage;
        $target = $projectPages * $perPage;
        $candidates = [];
        foreach ($trims as $key => $trim) {
            $spine = $size['w_mm'] - 2 * (float) $trim['w_mm'] - 2 * $bleed;
            if (abs($trimH - (float) $trim['h_mm']) > self::TRIM_TOLERANCE) {
                continue;
            }
            // Un dos plausible : au moins 0,5 mm et au plus 70 mm
            if ($spine < 0.5 || $spine > 70) {
                continue;
            }
            $candidates[] = [
                'key'      => $key,
                'trim'     => $trim,
                'spine_mm' => round($spine, 2),
                'score'    => ($key === (string) ($project['trim_format'] ?? '') ? 0 : 100)
                    + ($spine > $maxSpine ? 1000 : 0)
                    + abs($spine - $target),
            ];
        }
        usort($candidates, fn ($a, $b) => $a['score'] <=> $b['score']);
        $match = $candidates[0] ?? null;

        $expected = self::expected($project, $projectPages);
        $out = [
            'w_mm'        => $size['w_mm'],
            'h_mm'        => $size['h_mm'],
            'w_in'        => round($size['w_mm'] / 25.4, 2),
            'h_in'        => round($size['h_mm'] / 25.4, 2),
            'source'      => $size['source'],
            'dpi'         => $size['dpi'] ?? null,
            'pdf_pages'   => $size['pages'],
            'flat'        => $size['flat'],
            'flat_reason' => $size['reason'],
            'expected'    => $expected,
        ];

        if (!$match) {
            $out['verdict'] = 'inconnu';
            $out['detected'] = null;
            $out['message'] = 'Format non reconnu : cette planche ne correspond à aucun format KDP connu '
                . '(elle devrait mesurer ' . self::mm($expected['w_mm']) . ' × ' . self::mm($expected['h_mm'])
                . ' pour votre livre). Vérifiez qu\'il s\'agit bien de la couverture complète, fond perdu compris.';
            return $out;
        }

        $pagesImplied = $perPage > 0 ? (int) round($match['spine_mm'] / $perPage) : 0;
        $out['detected'] = [
            'trim'        => $match['key'],
            'trim_name'   => self::trimName($match['key']),
            'trim_label'  => $match['trim']['label'],
            'trim_w_mm'   => (float) $match['trim']['w_mm'],
            'trim_h_mm'   => (float) $match['trim']['h_mm'],
            'spine_mm'    => $match['spine_mm'],
            'pages'       => $pagesImplied,
        ];

        $sameTrim = $match['key'] === (string) ($project['trim_format'] ?? '');
        // Le dos tolère l'écart d'une dizaine de pages (arrondis d'imprimeur)
        $spineGap = abs($match['spine_mm'] - $expected['spine_mm']);
        $sameSpine = $spineGap <= max(0.6, $perPage * 12);

        if ($sameTrim && $sameSpine) {
            $out['verdict'] = 'ok';
            $out['message'] = 'Planche conforme : ' . $match['trim']['label'] . ', dos de '
                . self::mm($match['spine_mm']) . ' (≈ ' . $pagesImplied . ' pages). Elle correspond à votre livre.';
            return $out;
        }

        $out['verdict'] = 'attention';
        $details = [];
        if (!$sameTrim) {
            $details[] = 'format ' . $match['trim']['label'] . ' alors que votre livre est réglé sur '
                . ($trims[$project['trim_format']]['label'] ?? $project['trim_format']);
        }
        if (!$sameSpine) {
            $details[] = 'dos de ' . self::mm($match['spine_mm']) . ' (≈ ' . $pagesImplied . ' pages) alors que votre livre en compte '
                . $projectPages . ' (dos attendu ' . self::mm($expected['spine_mm']) . ')';
        }
        $out['message'] = 'Cette planche ne correspond pas encore à votre livre : ' . implode(' ; ', $details)
            . '. KDP refusera le fichier tant que les deux ne coïncident pas.';
        return $out;
    }

    /** Dimensions attendues de la planche pour ce projet. */
    public static function expected(array $project, int $pages): array
    {
        $bleed = (float) Config::get('kdp.bleed_mm', 3.175);
        $perPage = (float) Config::get('kdp.spine_per_page', 0.0572);
        $trims = (array) Config::get('trims', []);
        $trim = $trims[$project['trim_format'] ?? '6x9'] ?? reset($trims);
        $spine = $pages * $perPage;
        return [
            'trim'      => (string) ($project['trim_format'] ?? '6x9'),
            'trim_name' => self::trimName((string) ($project['trim_format'] ?? '6x9')),
            'pages'    => $pages,
            'spine_mm' => round($spine, 2),
            'w_mm'     => round(2 * (float) $trim['w_mm'] + $spine + 2 * $bleed, 1),
            'h_mm'     => round((float) $trim['h_mm'] + 2 * $bleed, 1),
        ];
    }

    // ── Découpe d'une planche image en trois panneaux ──────────────────────

    /**
     * Découpe une planche (image) en 4ème de couverture, dos et 1ère.
     * Le fond perdu extérieur est conservé sur les panneaux latéraux : c'est
     * exactement ce que KDP imprime.
     *
     * @return array<string,\GdImage>|null  ['back'=>…, 'spine'=>…, 'front'=>…]
     */
    public static function panels(string $imageFile, array $diagnosis): ?array
    {
        $detected = $diagnosis['detected'] ?? null;
        if (!$detected) {
            return null;
        }
        $im = @imagecreatefromstring((string) @file_get_contents($imageFile));
        if (!$im) {
            return null;
        }
        $w = imagesx($im);
        $h = imagesy($im);
        $bleed = (float) Config::get('kdp.bleed_mm', 3.175);
        $px = fn (float $mm): int => (int) round($mm * $w / $diagnosis['w_mm']);

        $backW  = $px($bleed + $detected['trim_w_mm']);
        $spineW = $px($detected['spine_mm']);
        $frontX = $backW + $spineW;

        $cut = function (int $x, int $width) use ($im, $h): ?\GdImage {
            $width = max(1, min($width, imagesx($im) - $x));
            $out = imagecrop($im, ['x' => max(0, $x), 'y' => 0, 'width' => $width, 'height' => $h]);
            return $out ?: null;
        };

        $panels = [
            'back'  => $cut(0, $backW),
            'spine' => $cut($backW, $spineW),
            'front' => $cut($frontX, $w - $frontX),
        ];
        imagedestroy($im);
        return array_filter($panels) ?: null;
    }

    /**
     * PDF prêt pour KDP à partir d'une planche image : une seule page à la
     * taille exacte de la planche, image posée bord à bord. Permet de
     * téléverser sur KDP une couverture fournie en JPEG.
     */
    public static function pdfFromImage(string $jpeg, array $diagnosis, string $destination): bool
    {
        // MiniPdf vit dans PdfBook.php : on s'assure que le fichier est chargé
        // avant d'instancier la classe (l'autoloader va par nom de fichier).
        class_exists(PdfBook::class);
        $mm = fn (float $v): float => $v * 72 / 25.4;
        $pdf = new MiniPdf($mm($diagnosis['w_mm']), $mm($diagnosis['h_mm']));
        $name = $pdf->addJpeg($jpeg);
        if (!$name) {
            return false;
        }
        $pdf->newPage();                       // une seule page, à la taille de la planche
        $pdf->image($name, 0, 0, $mm($diagnosis['w_mm']), $mm($diagnosis['h_mm']));
        return (bool) @file_put_contents($destination, $pdf->build());
    }

    /** « 8_5x11 » → « 8,5 × 11 po », lisible par un humain. */
    public static function trimName(string $key): string
    {
        $parts = explode('x', $key);
        $fmt = fn (string $v): string => str_replace('_', ',', $v);
        return count($parts) === 2 ? $fmt($parts[0]) . ' × ' . $fmt($parts[1]) . ' po' : $key;
    }

    private static function mm(float $v): string
    {
        return number_format($v, 1, ',', ' ') . ' mm';
    }
}
