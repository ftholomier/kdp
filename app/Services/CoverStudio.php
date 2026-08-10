<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * Studio de couvertures — génération d'images RASTER (GD), 100 % serveur.
 *
 * Produit des couvertures flat design variées (façon générateur de logo) :
 * plusieurs mises en page × palettes × motifs vectoriels flat, que l'auteur
 * parcourt et valide. Optionnellement, une illustration IA (Gemini image,
 * « nano banana ») est compositée dans la zone image de la maquette.
 *
 * Rendu serveur = pas de « tainted canvas », export JPG/PNG fiable, polices
 * embarquées (app/fonts), qualité impression.
 */
final class CoverStudio
{
    private const W = 1600;   // largeur eBook (px)
    private const H = 2560;   // hauteur eBook (px) — ratio 1.6 conseillé par KDP

    /** Palettes flat curées : c1 fond · c2 accent · c3 clair · c4 encre. */
    public const PALETTES = [
        ['name' => 'Nuit corail',   'c1' => '#1B2A4A', 'c2' => '#C4571F', 'c3' => '#F4EFE4', 'c4' => '#12203A'],
        ['name' => 'Azur',          'c1' => '#1E88C4', 'c2' => '#F4B740', 'c3' => '#F3F6F8', 'c4' => '#124A6B'],
        ['name' => 'Forêt',         'c1' => '#2F5D50', 'c2' => '#E0A03C', 'c3' => '#F2EFE6', 'c4' => '#1E3D34'],
        ['name' => 'Prune',         'c1' => '#5B2A4A', 'c2' => '#E8A0B4', 'c3' => '#F6EFF2', 'c4' => '#3C1B31'],
        ['name' => 'Sorbet',        'c1' => '#F27C56', 'c2' => '#2B3A67', 'c3' => '#FFF3EC', 'c4' => '#7A2E17'],
        ['name' => 'Menthe',        'c1' => '#1FA98A', 'c2' => '#2B3A4A', 'c3' => '#F0F7F4', 'c4' => '#0F5F4C'],
        ['name' => 'Encre & or',    'c1' => '#161616', 'c2' => '#D4A24A', 'c3' => '#F5F1E6', 'c4' => '#000000'],
        ['name' => 'Cobalt vif',    'c1' => '#2743D0', 'c2' => '#FF6B4A', 'c3' => '#EEF0FF', 'c4' => '#16267A'],
    ];

    public const LAYOUTS = ['affiche', 'bloc', 'cercle', 'bandeau', 'duo', 'diagonale', 'cadre'];

    public const MOTIFS = ['soleil', 'arches', 'montagnes', 'vagues', 'pastilles', 'feuille', 'etoile', 'blob'];

    // ── API haut niveau ────────────────────────────────────────────────────

    public static function palettes(): array
    {
        return self::PALETTES;
    }

    /**
     * Jeu de variantes (façon générateur de logo) : mises en page × palettes ×
     * motifs, variées et déterministes pour un seed donné (« Régénérer » = seed
     * différent). @return array<int,array{layout:string,palette:array,motif:string}>
     */
    public static function variants(int $seed, int $count = 8): array
    {
        $rng = abs($seed) % 2147483647 ?: 1;
        $next = function () use (&$rng): int {
            $rng = ($rng * 1103515245 + 12345) & 0x7fffffff;
            return $rng;
        };
        $out = [];
        $nL = count(self::LAYOUTS);
        $nP = count(self::PALETTES);
        $nM = count(self::MOTIFS);
        for ($i = 0; $i < $count; $i++) {
            $out[] = [
                'layout'  => self::LAYOUTS[($i + intdiv($seed, 3)) % $nL],
                'palette' => self::PALETTES[($next() + $i) % $nP],
                'motif'   => self::MOTIFS[$next() % $nM],
            ];
        }
        return $out;
    }

    /** Devine un motif flat pertinent à partir du thème/titre. */
    public static function motifFor(string $text): string
    {
        $t = mb_strtolower($text);
        $map = [
            'soleil'    => ['matin', 'réveil', 'soleil', 'jour', 'lumièr', 'énergie', 'été'],
            'montagnes' => ['montagne', 'randonn', 'voyage', 'sommet', 'objectif', 'défi'],
            'vagues'    => ['mer', 'océan', 'sommeil', 'calme', 'respir', 'zen', 'eau', 'surf'],
            'feuille'   => ['jardin', 'plante', 'nature', 'bio', 'vegan', 'cuisine', 'santé', 'green'],
            'pastilles' => ['argent', 'budget', 'finance', 'habitude', 'point', 'method'],
            'arches'    => ['carnet', 'journal', 'gratitude', 'écrit', 'porte'],
            'etoile'    => ['succ', 'star', 'réussite', 'motiv', 'rêve', 'ambition'],
        ];
        foreach ($map as $motif => $keys) {
            foreach ($keys as $k) {
                if (str_contains($t, $k)) {
                    return $motif;
                }
            }
        }
        return 'blob';
    }

    /** Vignette PNG (data URI) pour le sélecteur de variantes. */
    public static function thumbnail(array $spec, array $texts): string
    {
        $im = self::renderFront($spec, $texts, null);
        $tw = 320;
        $th = (int) round($tw * self::H / self::W);
        $thumb = imagecreatetruecolor($tw, $th);
        imagecopyresampled($thumb, $im, 0, 0, 0, 0, $tw, $th, self::W, self::H);
        ob_start();
        imagepng($thumb, null, 6);
        $png = (string) ob_get_clean();
        imagedestroy($im);
        imagedestroy($thumb);
        return 'data:image/png;base64,' . base64_encode($png);
    }

    /** JPEG pleine résolution de la 1ère de couverture (eBook / aperçu). */
    public static function frontJpeg(array $spec, array $texts, ?string $illustrationPath, int $quality = 92): string
    {
        $im = self::renderFront($spec, $texts, $illustrationPath);
        ob_start();
        imagejpeg($im, null, $quality);
        $jpg = (string) ob_get_clean();
        imagedestroy($im);
        return $jpg;
    }

    // ── Illustration IA (nano banana) ──────────────────────────────────────

    public static function illusPath(int $projectId): string
    {
        return (string) Config::get('paths.uploads') . '/cover-illus-' . $projectId . '.jpg';
    }

    public static function refPath(int $projectId): string
    {
        return (string) Config::get('paths.uploads') . '/cover-ref-' . $projectId . '.jpg';
    }

    /** Prompt par défaut, construit depuis le livre. */
    public static function defaultPrompt(array $texts): string
    {
        $title = trim((string) ($texts['title'] ?? ''));
        return 'Illustration du thème « ' . $title . ' » : objet ou scène emblématique du sujet, '
            . 'composition centrée, fond uni.';
    }

    /**
     * Génère l'illustration flat design via Gemini image et l'enregistre.
     * Le style flat est imposé autour du prompt utilisateur ; une image de
     * référence (refPath) est jointe si présente.
     */
    public static function generateIllustration(int $projectId, string $userPrompt, array $palette): string
    {
        $colors = implode(', ', array_filter([
            $palette['c1'] ?? null, $palette['c2'] ?? null, $palette['c3'] ?? null, $palette['c4'] ?? null,
        ]));
        $prompt = "Flat design vector-style illustration for a book cover. "
            . trim($userPrompt) . "\n"
            . "STYLE STRICT : flat design 2D, formes géométriques simples, aplats de couleurs unies, "
            . "sans dégradés complexes, sans ombres portées réalistes, sans texture photographique, "
            . "esthétique minimaliste et moderne, composition centrée avec de l'air autour. "
            . ($colors !== '' ? "Palette imposée : {$colors}. " : '')
            . "AUCUN texte, AUCUNE lettre, AUCUN mot dans l'image.";

        $reference = is_file(self::refPath($projectId)) ? self::refPath($projectId) : null;
        $binary = Gemini::image($prompt, $reference);

        $src = @imagecreatefromstring($binary);
        if (!$src) {
            throw new \RuntimeException("L'image générée est illisible — réessayez.");
        }
        $dir = (string) Config::get('paths.uploads');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        imagejpeg($src, self::illusPath($projectId), 94);
        imagedestroy($src);
        return self::illusPath($projectId);
    }

    // ── Rendu d'une 1ère de couverture ─────────────────────────────────────

    public static function renderFront(array $spec, array $texts, ?string $illustrationPath): \GdImage
    {
        $W = self::W;
        $H = self::H;
        $im = imagecreatetruecolor($W, $H);
        imagealphablending($im, true);
        imageantialias($im, true);

        $pal = $spec['palette'] ?? self::PALETTES[0];
        $c1 = self::alloc($im, $pal['c1']);
        $c2 = self::alloc($im, $pal['c2']);
        $c3 = self::alloc($im, $pal['c3']);
        $c4 = self::alloc($im, $pal['c4']);

        $layout = $spec['layout'] ?? 'bloc';
        $motif = $spec['motif'] ?? 'blob';
        $illus = $illustrationPath && is_file($illustrationPath) ? @imagecreatefromjpeg($illustrationPath) : null;

        $title    = trim((string) ($texts['title'] ?? 'Titre du livre'));
        $subtitle = trim((string) ($texts['subtitle'] ?? ''));
        $tagline  = trim((string) ($texts['tagline'] ?? ''));
        $author   = mb_strtoupper(trim((string) ($texts['author'] ?? '')));

        $serif  = self::font('InstrumentSerif-Regular.ttf');
        $italic = self::font('InstrumentSerif-Italic.ttf');
        $mono   = self::font('IBMPlexMono-Medium.ttf');

        // Aire d'illustration selon la mise en page (x, y, w, h) ou null
        $imgArea = null;
        $M = 130; // marge intérieure

        switch ($layout) {
            case 'affiche':
                // L'ILLUSTRATION EN GRAND : pleine page, du bord haut au bandeau
                // titre — c'est la mise en page appliquée automatiquement après
                // une génération d'illustration IA.
                $bandY = (int) round($H * 0.70);
                if ($illus) {
                    self::drawCover($im, $illus, 0, 0, $W, $bandY, false);
                } else {
                    imagefilledrectangle($im, 0, 0, $W, $bandY, $c1);
                    self::drawMotif($im, $motif, $W / 2, $bandY / 2, $W * 0.30, $c2, $c3);
                }
                // Bandeau titre flat + filet accent
                imagefilledrectangle($im, 0, $bandY, $W, $H, $c1);
                imagefilledrectangle($im, 0, $bandY, $W, $bandY + 16, $c2);
                self::textBlock($im, $serif, $title, $M, $bandY + 150, $W - 2 * $M, 96, 114, $c3, 'left', 3);
                if ($tagline) self::textBlock($im, $italic, $tagline, $M, $bandY + 150 + 3 * 114 - 40, $W - 2 * $M, 48, 58, $c2, 'left', 2);
                // Auteur : pastille flat en haut (lisible sur toute image)
                if ($author) {
                    $aw = self::width('IBMPlexMono-Medium.ttf', implode('', array_map(fn ($c) => $c . ' ', mb_str_split($author))), 40);
                    imagefilledrectangle($im, $M - 30, 96, $M + $aw + 40, 208, $c1);
                    imagefilledrectangle($im, $M - 30, 200, $M + $aw + 40, 208, $c2);
                    self::line($im, $mono, $author, 40, $M, 168, $c3, 6);
                }
                break;

            case 'bloc':
                imagefilledrectangle($im, 0, 0, $W, $H, $c3);
                imagefilledrectangle($im, 0, 0, $W, (int) round($H * 0.66), $c1);
                imagefilledrectangle($im, 0, (int) round($H * 0.66), $W, (int) round($H * 0.66) + 16, $c2);
                $imgArea = [$M, (int) round($H * 0.20), $W - 2 * $M, (int) round($H * 0.30)];
                self::motifOrImage($im, $illus, $motif, $imgArea, $c2, $c3);
                self::textBlock($im, $serif, $title, $M, (int) round($H * 0.50), $W - 2 * $M, 96, 118, $c3, 'left', 4);
                if ($author) self::line($im, $mono, $author, 44, $M, 150, $c3, 6);
                if ($tagline) self::textBlock($im, $italic, $tagline, $M, (int) round($H * 0.70), $W - 2 * $M, 52, 62, $c4, 'left', 3);
                if ($subtitle) self::textBlock($im, $mono, $subtitle, $M, (int) round($H * 0.84), $W - 2 * $M, 34, 46, self::mix($im, $c4, 0.7), 'left', 3);
                break;

            case 'cercle':
                imagefilledrectangle($im, 0, 0, $W, $H, $c3);
                $cx = (int) ($W / 2); $cy = (int) round($H * 0.34); $r = (int) round($W * 0.34);
                imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $c1);
                $imgArea = [$cx - $r + 40, $cy - $r + 40, ($r - 40) * 2, ($r - 40) * 2];
                self::motifOrImage($im, $illus, $motif, $imgArea, $c2, $c3, true);
                if ($author) self::line($im, $mono, $author, 42, 0, (int) round($H * 0.60), $c4, 8, $W);
                self::textBlock($im, $serif, $title, $M, (int) round($H * 0.64), $W - 2 * $M, 92, 108, $c4, 'center', 4);
                if ($tagline) self::textBlock($im, $italic, $tagline, (int) ($W * 0.14), (int) round($H * 0.86), (int) ($W * 0.72), 50, 60, $c2, 'center', 2);
                break;

            case 'bandeau':
                imagefilledrectangle($im, 0, 0, $W, $H, $c3);
                self::motifOrImage($im, $illus, $motif, [$M, (int) round($H * 0.12), $W - 2 * $M, (int) round($H * 0.34)], $c1, $c2);
                imagefilledrectangle($im, 0, (int) round($H * 0.50), $W, (int) round($H * 0.78), $c1);
                self::textBlock($im, $serif, $title, $M, (int) round($H * 0.545), $W - 2 * $M, 92, 110, $c3, 'left', 4);
                if ($tagline) self::textBlock($im, $italic, $tagline, $M, (int) round($H * 0.815), $W - 2 * $M, 50, 62, $c4, 'left', 2);
                if ($author) self::line($im, $mono, $author, 42, $M, (int) round($H * 0.92), $c4, 6);
                break;

            case 'duo':
                imagefilledrectangle($im, 0, 0, $W, $H, $c3);
                imagefilledrectangle($im, 0, (int) round($H * 0.56), $W, $H, $c1);
                $imgArea = [0, 0, $W, (int) round($H * 0.56)];
                self::motifOrImage($im, $illus, $motif, [$M, (int) round($H * 0.10), $W - 2 * $M, (int) round($H * 0.36)], $c2, $c1, false, $c3);
                self::textBlock($im, $serif, $title, $M, (int) round($H * 0.62), $W - 2 * $M, 94, 112, $c3, 'left', 4);
                if ($tagline) self::textBlock($im, $italic, $tagline, $M, (int) round($H * 0.85), $W - 2 * $M, 50, 62, $c2, 'left', 2);
                if ($author) self::line($im, $mono, $author, 42, $M, (int) round($H * 0.94), $c3, 6);
                break;

            case 'diagonale':
                imagefilledrectangle($im, 0, 0, $W, $H, $c1);
                imagefilledpolygon($im, [0, 0, $W, 0, $W, (int) round($H * 0.42), 0, (int) round($H * 0.62)], $c2);
                imagefilledpolygon($im, [0, (int) round($H * 0.62), $W, (int) round($H * 0.42), $W, (int) round($H * 0.50), 0, (int) round($H * 0.70)], $c3);
                self::motifOrImage($im, $illus, $motif, [$W - 520, 150, 380, 380], $c1, $c3, true);
                self::textBlock($im, $serif, $title, $M, (int) round($H * 0.70), $W - 2 * $M, 96, 116, $c3, 'left', 4);
                if ($tagline) self::textBlock($im, $italic, $tagline, $M, (int) round($H * 0.90), $W - 2 * $M, 48, 60, $c2, 'left', 2);
                if ($author) self::line($im, $mono, $author, 42, $M, 200, $c1, 8);
                break;

            case 'cadre':
            default:
                imagefilledrectangle($im, 0, 0, $W, $H, $c3);
                self::rectBorder($im, 60, 60, $W - 60, $H - 60, 6, $c1);
                self::motifOrImage($im, $illus, $motif, [(int) ($W / 2) - 190, (int) round($H * 0.16), 380, 380], $c2, $c3, true);
                if ($author) self::line($im, $mono, $author, 40, 0, (int) round($H * 0.44), $c4, 8, $W);
                self::textBlock($im, $serif, $title, (int) ($W * 0.12), (int) round($H * 0.50), (int) ($W * 0.76), 92, 110, $c4, 'center', 4);
                if ($tagline) self::textBlock($im, $italic, $tagline, (int) ($W * 0.16), (int) round($H * 0.74), (int) ($W * 0.68), 50, 62, $c2, 'center', 3);
                break;
        }

        if ($illus) {
            imagedestroy($illus);
        }
        return $im;
    }

    // ── Composition illustration / motif flat ──────────────────────────────

    private static function motifOrImage(\GdImage $im, ?\GdImage $illus, string $motif, array $area, int $shape, int $bg, bool $circle = false, ?int $shape2 = null): void
    {
        [$x, $y, $w, $h] = $area;
        if ($illus) {
            self::drawCover($im, $illus, $x, $y, $w, $h, $circle);
            return;
        }
        self::drawMotif($im, $motif, $x + $w / 2, $y + $h / 2, min($w, $h) * 0.42, $shape, $shape2 ?? $bg);
    }

    /** Dessine un motif flat (icône vectorielle géométrique). */
    private static function drawMotif(\GdImage $im, string $type, float $cx, float $cy, float $r, int $col, int $col2): void
    {
        $cx = (int) $cx; $cy = (int) $cy; $r = (int) $r;
        switch ($type) {
            case 'soleil':
                for ($a = 0; $a < 360; $a += 30) {
                    $rad = deg2rad($a);
                    self::thickLine($im, $cx + cos($rad) * $r * 1.25, $cy + sin($rad) * $r * 1.25, $cx + cos($rad) * $r * 1.6, $cy + sin($rad) * $r * 1.6, 14, $col);
                }
                imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $col);
                imagefilledellipse($im, $cx, $cy, (int) ($r * 1.1), (int) ($r * 1.1), $col2);
                break;
            case 'arches':
                imagefilledarc($im, $cx, $cy + (int) ($r * 0.6), (int) ($r * 2.4), (int) ($r * 2.4), 180, 360, $col, IMG_ARC_PIE);
                imagefilledarc($im, $cx, $cy + (int) ($r * 0.6), (int) ($r * 1.5), (int) ($r * 1.5), 180, 360, $col2, IMG_ARC_PIE);
                imagefilledarc($im, $cx, $cy + (int) ($r * 0.6), (int) ($r * 0.7), (int) ($r * 0.7), 180, 360, $col, IMG_ARC_PIE);
                break;
            case 'montagnes':
                imagefilledpolygon($im, [$cx - $r, $cy + $r, $cx - (int) ($r * 0.1), $cy - (int) ($r * 0.6), $cx + (int) ($r * 0.5), $cy + $r], $col);
                imagefilledpolygon($im, [$cx - (int) ($r * 0.2), $cy + $r, $cx + (int) ($r * 0.6), $cy - $r, $cx + (int) ($r * 1.3), $cy + $r], $col2);
                break;
            case 'vagues':
                for ($i = 0; $i < 3; $i++) {
                    $yy = $cy - (int) ($r * 0.5) + $i * (int) ($r * 0.5);
                    $cc = $i % 2 ? $col2 : $col;
                    for ($xx = -1; $xx <= 1; $xx++) {
                        imagefilledarc($im, $cx + $xx * $r, $yy, (int) ($r * 1.1), (int) ($r * 0.7), 0, 180, $cc, IMG_ARC_PIE);
                    }
                }
                break;
            case 'pastilles':
                $step = (int) ($r * 0.8);
                for ($gx = -1; $gx <= 1; $gx++) {
                    for ($gy = -1; $gy <= 1; $gy++) {
                        $cc = (($gx + $gy) % 2 === 0) ? $col : $col2;
                        imagefilledellipse($im, $cx + $gx * $step, $cy + $gy * $step, (int) ($r * 0.5), (int) ($r * 0.5), $cc);
                    }
                }
                break;
            case 'feuille':
                imagefilledarc($im, $cx, $cy, $r * 2, $r * 2, 270, 90, $col, IMG_ARC_PIE);
                imagefilledarc($im, $cx, $cy, $r * 2, $r * 2, 90, 270, $col, IMG_ARC_PIE);
                imagefilledellipse($im, $cx, $cy, (int) ($r * 2), (int) ($r * 0.9), $col);
                self::thickLine($im, $cx - $r, $cy, $cx + $r, $cy, 10, $col2);
                break;
            case 'etoile':
                $pts = [];
                for ($i = 0; $i < 10; $i++) {
                    $rr = $i % 2 ? $r * 0.45 : $r;
                    $ang = deg2rad($i * 36 - 90);
                    $pts[] = (int) ($cx + cos($ang) * $rr);
                    $pts[] = (int) ($cy + sin($ang) * $rr);
                }
                imagefilledpolygon($im, $pts, $col);
                break;
            case 'blob':
            default:
                imagefilledellipse($im, $cx, $cy, $r * 2, (int) ($r * 1.7), $col);
                imagefilledellipse($im, $cx + (int) ($r * 0.5), $cy - (int) ($r * 0.4), $r, $r, $col2);
                imagefilledellipse($im, $cx - (int) ($r * 0.6), $cy + (int) ($r * 0.3), (int) ($r * 0.8), (int) ($r * 0.8), $col2);
                break;
        }
    }

    // ── Utilitaires GD ─────────────────────────────────────────────────────

    private static function drawCover(\GdImage $dst, \GdImage $src, int $x, int $y, int $w, int $h, bool $circle): void
    {
        $sw = imagesx($src);
        $sh = imagesy($src);
        $scale = max($w / $sw, $h / $sh);
        $nw = (int) ($sw * $scale);
        $nh = (int) ($sh * $scale);
        $tmp = imagecreatetruecolor($w, $h);
        imagecopyresampled($tmp, $src, 0, 0, (int) (($sw - $w / $scale) / 2), (int) (($sh - $h / $scale) / 2), $w, $h, (int) ($w / $scale), (int) ($h / $scale));
        if ($circle) {
            $mask = imagecreatetruecolor($w, $h);
            $trans = imagecolorallocatealpha($mask, 0, 0, 0, 127);
            imagefill($mask, 0, 0, $trans);
            imagesavealpha($mask, true);
            $opaque = imagecolorallocate($mask, 255, 255, 255);
            imagefilledellipse($mask, (int) ($w / 2), (int) ($h / 2), $w, $h, $opaque);
            for ($iy = 0; $iy < $h; $iy++) {
                for ($ix = 0; $ix < $w; $ix++) {
                    if (((int) imagecolorat($mask, $ix, $iy) & 0xFF000000) >> 24 > 100) {
                        imagesetpixel($tmp, $ix, $iy, imagecolorallocatealpha($tmp, 0, 0, 0, 127));
                    }
                }
            }
            imagedestroy($mask);
        }
        imagecopy($dst, $tmp, $x, $y, 0, 0, $w, $h);
        imagedestroy($tmp);
    }

    private static function textBlock(\GdImage $im, string $font, string $text, int $x, int $y, int $maxW, int $size, int $lineH, int $color, string $align, int $maxLines): void
    {
        if ($text === '') {
            return;
        }
        // Auto-ajuste la taille pour tenir en <= maxLines
        $lines = self::wrap($font, $text, $size, $maxW);
        while (count($lines) > $maxLines && $size > 34) {
            $size -= 6;
            $lineH = (int) ($lineH * 0.94);
            $lines = self::wrap($font, $text, $size, $maxW);
        }
        $lines = array_slice($lines, 0, $maxLines);
        foreach ($lines as $i => $line) {
            $lw = self::width($font, $line, $size);
            $lx = $align === 'center' ? $x + (int) (($maxW - $lw) / 2) : $x;
            imagettftext($im, $size, 0, $lx, $y + $i * $lineH, $color, self::font($font), $line);
        }
    }

    private static function line(\GdImage $im, string $font, string $text, int $size, int $x, int $y, int $color, float $tracking, ?int $centerIn = null): void
    {
        if ($text === '') {
            return;
        }
        // Interlettrage manuel (GD ne gère pas letter-spacing)
        $spaced = $tracking > 3 ? implode('', array_map(fn ($c) => $c . ' ', mb_str_split($text))) : $text;
        if ($centerIn !== null) {
            $w = self::width($font, $spaced, $size);
            $x = (int) (($centerIn - $w) / 2);
        }
        imagettftext($im, $size, 0, $x, $y, $color, self::font($font), $spaced);
    }

    private static function wrap(string $font, string $text, int $size, int $maxW): array
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $lines = [];
        $cur = '';
        foreach ($words as $w) {
            $try = $cur === '' ? $w : $cur . ' ' . $w;
            if (self::width($font, $try, $size) > $maxW && $cur !== '') {
                $lines[] = $cur;
                $cur = $w;
            } else {
                $cur = $try;
            }
        }
        if ($cur !== '') {
            $lines[] = $cur;
        }
        return $lines;
    }

    private static function width(string $font, string $text, int $size): int
    {
        $box = imagettfbbox($size, 0, self::font($font), $text);
        return abs($box[2] - $box[0]);
    }

    private static function thickLine(\GdImage $im, float $x1, float $y1, float $x2, float $y2, int $thick, int $color): void
    {
        imagesetthickness($im, $thick);
        imageline($im, (int) $x1, (int) $y1, (int) $x2, (int) $y2, $color);
        imagesetthickness($im, 1);
    }

    private static function rectBorder(\GdImage $im, int $x1, int $y1, int $x2, int $y2, int $thick, int $color): void
    {
        imagesetthickness($im, $thick);
        imagerectangle($im, $x1, $y1, $x2, $y2, $color);
        imagesetthickness($im, 1);
    }

    private static function alloc(\GdImage $im, string $hex): int
    {
        [$r, $g, $b] = self::rgb($hex);
        return imagecolorallocate($im, $r, $g, $b);
    }

    private static function mix(\GdImage $im, int $color, float $alpha): int
    {
        $r = ($color >> 16) & 0xFF;
        $g = ($color >> 8) & 0xFF;
        $b = $color & 0xFF;
        return imagecolorallocatealpha($im, $r, $g, $b, (int) ((1 - $alpha) * 127));
    }

    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    private static function font(string $file): string
    {
        // imagettftext accepte le chemin complet ; on renvoie l'absolu
        return str_contains($file, '/') ? $file : APP_ROOT . '/app/fonts/' . $file;
    }
}
