<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * Studio de couvertures — moteur à ÉLÉMENTS libres, rendu GD haute résolution.
 *
 * La couverture est une liste d'éléments éditables (fond, blocs, motif,
 * illustration, textes) avec position/taille en % du format, police Google
 * Fonts embarquée, graisse, italique, alignement et couleur par élément.
 * L'éditeur visuel du navigateur manipule ces éléments (glisser-déposer,
 * traits d'alignement) ; le serveur rend le JPEG final 1600×2560.
 *
 * Les « mises en page » historiques (affiche, bloc, cercle…) deviennent des
 * générateurs d'éléments : choisir une version = repartir d'un jeu d'éléments
 * que l'on peut ensuite retoucher librement.
 */
final class CoverStudio
{
    public const W = 1600;
    public const H = 2560;

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

    /**
     * Polices Google Fonts embarquées (app/fonts, licence OFL).
     * slug => [label, fichier, fichier italique|null, famille CSS (aperçu navigateur)]
     */
    /** [label, regular, italique|null, css, gras réel|null] — fichiers STATIQUES
     *  uniquement : les TTF variables rendaient leur instance par défaut
     *  (Thin/ExtraLight) côté serveur, loin de l'aperçu navigateur. */
    public const FONTS = [
        'instrument-serif' => ['Instrument Serif',  'InstrumentSerif-Regular.ttf', 'InstrumentSerif-Italic.ttf', "'Instrument Serif', serif",     null],
        'playfair'         => ['Playfair Display',  'PlayfairDisplay.ttf',         'PlayfairDisplay-Italic.ttf', "'Playfair Display', serif",     null],
        'dm-serif'         => ['DM Serif Display',  'DMSerifDisplay.ttf',          'DMSerifDisplay-Italic.ttf',  "'DM Serif Display', serif",     null],
        'abril'            => ['Abril Fatface',     'AbrilFatface.ttf',            null,                         "'Abril Fatface', serif",        null],
        'lora'             => ['Lora',              'Lora.ttf',                    'Lora-Italic.ttf',            "'Lora', serif",                 null],
        'montserrat'       => ['Montserrat',        'Montserrat-Regular.ttf',      null,                         "'Montserrat', sans-serif",      'Montserrat-SemiBold.ttf'],
        'poppins'          => ['Poppins',           'Poppins-SemiBold.ttf',        null,                         "'Poppins', sans-serif",         null],
        'oswald'           => ['Oswald',            'Oswald.ttf',                  null,                         "'Oswald', sans-serif",          null],
        'bebas'            => ['Bebas Neue',        'BebasNeue.ttf',               null,                         "'Bebas Neue', sans-serif",      null],
        'josefin'          => ['Josefin Sans',      'JosefinSans-Regular.ttf',     null,                         "'Josefin Sans', sans-serif",    'JosefinSans-SemiBold.ttf'],
        'nunito'           => ['Nunito',            'Nunito-Regular.ttf',          null,                         "'Nunito', sans-serif",          'Nunito-SemiBold.ttf'],
        'plex-mono'        => ['IBM Plex Mono',     'IBMPlexMono-Medium.ttf',      null,                         "'IBM Plex Mono', monospace",    null],
        'instrument-sans'  => ['Instrument Sans',   'InstrumentSans.ttf',          null,                         "'Instrument Sans', sans-serif", null],
    ];

    /** Registre pour l'interface (sélecteur avec aperçu). */
    public static function fonts(): array
    {
        return array_map(
            fn ($slug, $def) => ['slug' => $slug, 'label' => $def[0], 'css' => $def[3], 'has_italic' => $def[2] !== null],
            array_keys(self::FONTS),
            self::FONTS
        );
    }

    public static function palettes(): array
    {
        return self::PALETTES;
    }

    // ── Variantes (générateur de versions) ─────────────────────────────────

    public static function variants(int $seed, int $count = 8): array
    {
        $rng = abs($seed) % 2147483647 ?: 1;
        $next = function () use (&$rng): int {
            $rng = ($rng * 1103515245 + 12345) & 0x7fffffff;
            return $rng;
        };
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = [
                'layout'  => self::LAYOUTS[($i + intdiv($seed, 3)) % count(self::LAYOUTS)],
                'palette' => self::PALETTES[($next() + $i) % count(self::PALETTES)],
                'motif'   => self::MOTIFS[$next() % count(self::MOTIFS)],
            ];
        }
        return $out;
    }

    public static function motifFor(string $text): string
    {
        $t = mb_strtolower($text);
        $map = [
            'soleil'    => ['matin', 'réveil', 'soleil', 'jour', 'lumièr', 'énergie', 'été'],
            'montagnes' => ['montagne', 'randonn', 'voyage', 'sommet', 'objectif', 'défi'],
            'vagues'    => ['mer', 'océan', 'sommeil', 'calme', 'respir', 'zen', 'eau', 'glace', 'surf'],
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

    // ── Mises en page → ÉLÉMENTS ───────────────────────────────────────────

    /**
     * Construit le jeu d'éléments initial d'une mise en page nommée.
     * Chaque élément : id, type (rect|motif|image|text), x/y/w/h en % du
     * format, et pour les textes : text, font, size (% de la largeur),
     * weight, italic, align, color, lh, maxLines.
     * NB : l'auteur n'apparaît volontairement pas en 1ère de couverture.
     */
    public static function layoutElements(string $layout, array $palette, string $motif, array $texts, bool $hasIllustration): array
    {
        $c1 = $palette['c1'] ?? '#1B2A4A';
        $c2 = $palette['c2'] ?? '#C4571F';
        $c3 = $palette['c3'] ?? '#F4EFE4';
        $c4 = $palette['c4'] ?? '#12203A';
        $title    = trim((string) ($texts['title'] ?? 'Titre du livre'));
        $subtitle = trim((string) ($texts['subtitle'] ?? ''));
        $tagline  = trim((string) ($texts['tagline'] ?? ''));

        $text = function (string $id, string $content, array $o) : array {
            return array_merge([
                'id' => $id, 'type' => 'text', 'text' => $content,
                'font' => 'instrument-serif', 'size' => 5.5, 'weight' => 400,
                'italic' => false, 'align' => 'left', 'lh' => 1.18, 'maxLines' => 4,
            ], $o);
        };
        $img = fn (float $x, float $y, float $w, float $h, bool $round = false) =>
            ['id' => 'illus', 'type' => 'image', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'round' => $round];
        $mot = fn (float $x, float $y, float $w, float $h, string $ca, string $cb) =>
            ['id' => 'motif', 'type' => 'motif', 'motif' => $motif, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'color' => $ca, 'color2' => $cb];
        $rect = fn (string $id, float $x, float $y, float $w, float $h, string $c) =>
            ['id' => $id, 'type' => 'rect', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'color' => $c];

        $els = [];
        switch ($layout) {
            case 'affiche':
                $els[] = $rect('fond', 0, 0, 100, 100, $c1);
                $els[] = $hasIllustration ? $img(0, 0, 100, 70) : $mot(24, 12, 52, 42, $c2, $c3);
                $els[] = $rect('bandeau', 0, 70, 100, 30, $c1);
                $els[] = $rect('filet', 0, 70, 100, 0.65, $c2);
                $els[] = $text('title', $title, ['x' => 8, 'y' => 74.5, 'w' => 84, 'size' => 6.0, 'color' => $c3, 'maxLines' => 3]);
                if ($tagline)  $els[] = $text('tagline', $tagline, ['x' => 8, 'y' => 89, 'w' => 84, 'size' => 3.0, 'color' => $c2, 'italic' => true, 'maxLines' => 2]);
                break;

            case 'bloc':
                $els[] = $rect('fond', 0, 0, 100, 100, $c3);
                $els[] = $rect('bloc', 0, 0, 100, 66, $c1);
                $els[] = $rect('filet', 0, 66, 100, 0.65, $c2);
                $els[] = $hasIllustration ? $img(8, 16, 84, 32) : $mot(26, 14, 48, 34, $c2, $c3);
                $els[] = $text('title', $title, ['x' => 8, 'y' => 50, 'w' => 84, 'size' => 6.0, 'color' => $c3, 'maxLines' => 4]);
                if ($tagline)  $els[] = $text('tagline', $tagline, ['x' => 8, 'y' => 70, 'w' => 84, 'size' => 3.2, 'color' => $c4, 'italic' => true, 'maxLines' => 2]);
                if ($subtitle) $els[] = $text('subtitle', $subtitle, ['x' => 8, 'y' => 84, 'w' => 84, 'size' => 2.1, 'color' => $c4, 'font' => 'plex-mono', 'maxLines' => 3]);
                break;

            case 'cercle':
                $els[] = $rect('fond', 0, 0, 100, 100, $c3);
                $els[] = ['id' => 'cercle', 'type' => 'ellipse', 'x' => 16, 'y' => 8, 'w' => 68, 'h' => 42.5, 'color' => $c1];
                $els[] = $hasIllustration ? $img(20, 10.5, 60, 37.5, true) : $mot(30, 16, 40, 26, $c2, $c3);
                $els[] = $text('title', $title, ['x' => 10, 'y' => 58, 'w' => 80, 'size' => 5.6, 'color' => $c4, 'align' => 'center', 'maxLines' => 4]);
                if ($tagline) $els[] = $text('tagline', $tagline, ['x' => 14, 'y' => 84, 'w' => 72, 'size' => 3.0, 'color' => $c2, 'italic' => true, 'align' => 'center', 'maxLines' => 2]);
                break;

            case 'bandeau':
                $els[] = $rect('fond', 0, 0, 100, 100, $c3);
                $els[] = $hasIllustration ? $img(8, 10, 84, 36) : $mot(24, 12, 52, 32, $c1, $c2);
                $els[] = $rect('bandeau', 0, 50, 100, 28, $c1);
                $els[] = $text('title', $title, ['x' => 8, 'y' => 54, 'w' => 84, 'size' => 5.8, 'color' => $c3, 'maxLines' => 3]);
                if ($tagline) $els[] = $text('tagline', $tagline, ['x' => 8, 'y' => 81.5, 'w' => 84, 'size' => 3.1, 'color' => $c4, 'italic' => true, 'maxLines' => 2]);
                break;

            case 'duo':
                $els[] = $rect('fond', 0, 0, 100, 56, $c3);
                $els[] = $rect('bloc', 0, 56, 100, 44, $c1);
                $els[] = $hasIllustration ? $img(8, 8, 84, 40) : $mot(26, 10, 48, 36, $c2, $c1);
                $els[] = $text('title', $title, ['x' => 8, 'y' => 61, 'w' => 84, 'size' => 5.9, 'color' => $c3, 'maxLines' => 4]);
                if ($tagline) $els[] = $text('tagline', $tagline, ['x' => 8, 'y' => 85, 'w' => 84, 'size' => 3.1, 'color' => $c2, 'italic' => true, 'maxLines' => 2]);
                break;

            case 'diagonale':
                $els[] = $rect('fond', 0, 0, 100, 100, $c1);
                $els[] = ['id' => 'diag', 'type' => 'poly', 'points' => [[0, 0], [100, 0], [100, 42], [0, 62]], 'color' => $c2, 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 62];
                $els[] = ['id' => 'diag2', 'type' => 'poly', 'points' => [[0, 62], [100, 42], [100, 50], [0, 70]], 'color' => $c3, 'x' => 0, 'y' => 42, 'w' => 100, 'h' => 28];
                $els[] = $hasIllustration ? $img(58, 6, 34, 21, true) : $mot(60, 6, 30, 19, $c1, $c3);
                $els[] = $text('title', $title, ['x' => 8, 'y' => 71, 'w' => 84, 'size' => 6.0, 'color' => $c3, 'maxLines' => 3]);
                if ($tagline) $els[] = $text('tagline', $tagline, ['x' => 8, 'y' => 90, 'w' => 84, 'size' => 3.0, 'color' => $c2, 'italic' => true, 'maxLines' => 2]);
                break;

            case 'cadre':
            default:
                $els[] = $rect('fond', 0, 0, 100, 100, $c3);
                $els[] = ['id' => 'cadre', 'type' => 'frame', 'x' => 3.75, 'y' => 2.35, 'w' => 92.5, 'h' => 95.3, 'color' => $c1, 'thick' => 0.4];
                $els[] = $hasIllustration ? $img(31, 12, 38, 23.75, true) : $mot(34, 13, 32, 20, $c2, $c3);
                $els[] = $text('title', $title, ['x' => 12, 'y' => 46, 'w' => 76, 'size' => 5.6, 'color' => $c4, 'align' => 'center', 'maxLines' => 4]);
                if ($tagline) $els[] = $text('tagline', $tagline, ['x' => 16, 'y' => 72, 'w' => 68, 'size' => 3.0, 'color' => $c2, 'italic' => true, 'align' => 'center', 'maxLines' => 3]);
                break;
        }
        return $els;
    }

    // ── Rendu GD des éléments ──────────────────────────────────────────────

    public static function renderElements(array $els, ?string $illustrationPath, int $W = self::W, int $H = self::H): \GdImage
    {
        $im = imagecreatetruecolor($W, $H);
        imagealphablending($im, true);
        imageantialias($im, true);
        imagefilledrectangle($im, 0, 0, $W, $H, imagecolorallocate($im, 245, 241, 230));

        $px = fn (float $p): int => (int) round($p * $W / 100);
        $py = fn (float $p): int => (int) round($p * $H / 100);
        $illus = $illustrationPath && is_file($illustrationPath) ? @imagecreatefromjpeg($illustrationPath) : null;

        foreach ($els as $el) {
            $type = (string) ($el['type'] ?? '');
            $color = self::alloc($im, (string) ($el['color'] ?? '#1B2A4A'));
            $x = $px((float) ($el['x'] ?? 0));
            $y = $py((float) ($el['y'] ?? 0));
            $w = $px((float) ($el['w'] ?? 10));
            $h = $py((float) ($el['h'] ?? 10));

            switch ($type) {
                case 'rect':
                    imagefilledrectangle($im, $x, $y, $x + $w, $y + $h, $color);
                    break;
                case 'ellipse':
                    imagefilledellipse($im, $x + (int) ($w / 2), $y + (int) ($h / 2), $w, $h, $color);
                    break;
                case 'poly':
                    $pts = [];
                    foreach ((array) ($el['points'] ?? []) as $pt) {
                        $pts[] = $px((float) $pt[0]);
                        $pts[] = $py((float) $pt[1]);
                    }
                    if (count($pts) >= 6) {
                        imagefilledpolygon($im, $pts, $color);
                    }
                    break;
                case 'frame':
                    imagesetthickness($im, max(2, $px((float) ($el['thick'] ?? 0.4))));
                    imagerectangle($im, $x, $y, $x + $w, $y + $h, $color);
                    imagesetthickness($im, 1);
                    break;
                case 'motif':
                    self::drawMotif(
                        $im, (string) ($el['motif'] ?? 'blob'),
                        $x + $w / 2, $y + $h / 2, min($w, $h) * 0.42,
                        $color, self::alloc($im, (string) ($el['color2'] ?? '#F4EFE4'))
                    );
                    break;
                case 'image':
                    if ($illus) {
                        self::drawCover($im, $illus, $x, $y, $w, $h, !empty($el['round']));
                    } else {
                        imagefilledrectangle($im, $x, $y, $x + $w, $y + $h, self::alloc($im, '#D9D0BE'));
                    }
                    break;
                case 'text':
                    self::drawTextElement($im, $el, $x, $y, $w, $W, $H);
                    break;
            }
        }
        if ($illus) {
            imagedestroy($illus);
        }
        return $im;
    }

    private static function drawTextElement(\GdImage $im, array $el, int $x, int $y, int $w, int $W = self::W, int $H = self::H): void
    {
        $content = trim((string) ($el['text'] ?? ''));
        if ($content === '') {
            return;
        }
        $slug = (string) ($el['font'] ?? 'instrument-serif');
        $italic = !empty($el['italic']);
        $wantBold = (int) ($el['weight'] ?? 400) >= 600;
        // Graisse RÉELLE quand la famille a un fichier SemiBold ; sinon faux gras
        $font = self::fontFile($slug, $italic, $wantBold);
        $bold = $wantBold && !self::hasBoldFile($slug, $italic);
        $size = max(14, (int) round((float) ($el['size'] ?? 5.5) * $W / 100));
        $lh = (float) ($el['lh'] ?? 1.18);
        $maxLines = (int) ($el['maxLines'] ?? 0);
        $align = (string) ($el['align'] ?? 'left');
        $color = self::alloc($im, (string) ($el['color'] ?? '#1A1A17'));

        // Paragraphes séparés par une ligne vide ('' = espace vertical)
        $build = function (int $sz) use ($font, $content, $w): array {
            $lines = [];
            foreach (preg_split('/\n\s*\n/', $content) ?: [] as $i => $paragraph) {
                if ($i > 0) {
                    $lines[] = '';
                }
                foreach (self::wrapPath($font, trim($paragraph), $sz, $w) as $line) {
                    $lines[] = $line;
                }
            }
            return $lines;
        };
        $lines = $build($size);
        if ($maxLines > 0) {
            while (count($lines) > $maxLines && $size > 20) {
                $size = (int) ($size * 0.93);
                $lines = $build($size);
            }
            $lines = array_slice($lines, 0, $maxLines);
        }
        // Ajustement à une hauteur maximale (ex. texte de 4ème de couverture) :
        // la taille descend jusqu'à ce que le bloc tienne dans sa zone.
        $maxH = isset($el['maxH']) ? (float) $el['maxH'] * $H / 100 : 0.0;
        if ($maxH > 0) {
            while ($size > 16 && count($lines) * $size * $lh * 1.35 > $maxH) {
                $size = (int) ($size * 0.94);
                $lines = $build($size);
                if ($maxLines > 0) {
                    $lines = array_slice($lines, 0, $maxLines);
                }
            }
        }
        $lineH = (int) round($size * $lh * 1.35); // pt → px approx.
        $baseline = $y + (int) round($size * 1.15);
        $off = max(1, (int) round($size / 34)); // épaisseur du faux gras

        foreach ($lines as $i => $line) {
            if ($line === '') {
                continue; // interligne de paragraphe
            }
            $lw = self::widthPath($font, $line, $size);
            $lx = match ($align) {
                'center' => $x + (int) (($w - $lw) / 2),
                'right'  => $x + $w - $lw,
                default  => $x,
            };
            $ly = $baseline + $i * $lineH;
            imagettftext($im, $size, 0, $lx, $ly, $color, $font, $line);
            if ($bold) {
                imagettftext($im, $size, 0, $lx + $off, $ly, $color, $font, $line);
                imagettftext($im, $size, 0, $lx, $ly + $off, $color, $font, $line);
            }
        }
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

    /**
     * VOTRE couverture déjà prête : 'wrap' = PDF broché complet (4ème + dos +
     * 1ère, prêt pour KDP), 'front' = image de 1ère de couverture (eBook).
     * Quand ces fichiers existent, ils priment sur la couverture composée.
     */
    public static function customCoverPath(int $projectId, string $kind): string
    {
        $kind = $kind === 'wrap' ? 'wrap' : 'front';
        $ext = $kind === 'wrap' ? 'pdf' : 'jpg';
        return (string) Config::get('paths.uploads') . '/cover-custom-' . $kind . '-' . $projectId . '.' . $ext;
    }

    /** @return array{wrap:bool,front:bool,wrap_preview:bool} */
    public static function customCovers(int $projectId): array
    {
        $wrap = is_file(self::customCoverPath($projectId, 'wrap'));
        return [
            'wrap'         => $wrap,
            'front'        => is_file(self::customCoverPath($projectId, 'front')),
            // Aperçu image du PDF : vous voyez votre couverture dans le studio
            // même si le navigateur n'affiche pas les PDF en ligne.
            'wrap_preview' => $wrap && self::wrapPreview($projectId) !== null,
        ];
    }

    /** Oublie l'aperçu (couverture retirée ou remplacée). */
    public static function forgetWrapPreview(int $projectId): void
    {
        @unlink(self::wrapPreviewPath($projectId));
    }

    private static function wrapPreviewPath(int $projectId): string
    {
        return (string) Config::get('paths.uploads') . '/cover-custom-wrap-preview-' . $projectId . '.jpg';
    }

    /**
     * APERÇU de votre PDF de couverture, sans aucune dépendance externe
     * (ni Ghostscript ni ImageMagick, absents des hébergements mutualisés) :
     * les couvertures KDP sont quasiment toujours exportées comme une grande
     * image JPEG posée pleine page. On récupère donc directement cette image
     * dans le PDF. Un PDF purement vectoriel n'en contient pas : dans ce cas
     * on renvoie null et l'interface propose la visionneuse du navigateur.
     *
     * @return string|null chemin de l'aperçu JPEG, ou null si non extractible
     */
    public static function wrapPreview(int $projectId): ?string
    {
        $pdf = self::customCoverPath($projectId, 'wrap');
        if (!is_file($pdf)) {
            return null;
        }
        $preview = self::wrapPreviewPath($projectId);
        if (is_file($preview) && filemtime($preview) >= filemtime($pdf)) {
            return $preview;
        }
        @unlink($preview);

        $jpeg = self::biggestJpegIn((string) @file_get_contents($pdf));
        if ($jpeg === null) {
            return null;
        }
        $src = @imagecreatefromstring($jpeg);
        if (!$src) {
            return null;
        }
        // Aperçu allégé : 1400 px de large suffisent largement à l'écran.
        $w = imagesx($src);
        $h = imagesy($src);
        $max = 1400;
        if ($w > $max) {
            $dst = imagescale($src, $max, (int) round($h * $max / $w));
            if ($dst) {
                imagedestroy($src);
                $src = $dst;
            }
        }
        imagejpeg($src, $preview, 88);
        imagedestroy($src);
        return is_file($preview) ? $preview : null;
    }

    /**
     * Plus grande image JPEG (filtre /DCTDecode) contenue dans un PDF : c'est
     * l'artwork de la couverture. Lecture directe des flux, le JPEG étant
     * stocké tel quel dans le fichier.
     */
    private static function biggestJpegIn(string $raw): ?string
    {
        $best = null;
        $offset = 0;
        while (($pos = strpos($raw, 'stream', $offset)) !== false) {
            $dictStart = max(0, $pos - 2000);
            $dict = substr($raw, $dictStart, $pos - $dictStart);
            $offset = $pos + 6;
            // Le dictionnaire de CE flux : après le dernier « obj » rencontré
            $objPos = strrpos($dict, 'obj');
            if ($objPos !== false) {
                $dict = substr($dict, $objPos);
            }
            if (!str_contains($dict, 'DCTDecode') || !str_contains($dict, '/Image')) {
                continue;
            }
            $start = $pos + 6;
            if (substr($raw, $start, 2) === "\r\n") {
                $start += 2;
            } elseif (in_array(substr($raw, $start, 1), ["\n", "\r"], true)) {
                $start += 1;
            }
            $end = strpos($raw, 'endstream', $start);
            if ($end === false) {
                continue;
            }
            $data = substr($raw, $start, $end - $start);
            // Un JPEG commence par FFD8 : garde-fou contre un flux mal découpé
            if (substr($data, 0, 2) !== "\xFF\xD8") {
                continue;
            }
            if ($best === null || strlen($data) > strlen($best)) {
                $best = $data;
            }
            $offset = $end + 9;
        }
        if ($best === null) {
            return null;
        }
        $info = @getimagesizefromstring($best);
        return ($info && ($info[2] ?? 0) === IMAGETYPE_JPEG && $info[0] >= 400) ? $best : null;
    }

    /**
     * BIBLIOTHÈQUE d'illustrations du projet : chaque génération IA y est
     * conservée (rien n'est écrasé). L'illustration ACTIVE reste illusPath(),
     * simple copie de l'entrée choisie.
     */
    public static function illusItemPath(int $projectId, string $slug): string
    {
        $slug = preg_replace('/[^a-z0-9]/i', '', $slug) ?: 'x';
        return (string) Config::get('paths.uploads') . '/cover-lib-' . $projectId . '-' . $slug . '.jpg';
    }

    /** @return array<int,array{slug:string,created_at:int,active:bool}> du plus récent au plus ancien */
    public static function illusLibrary(int $projectId): array
    {
        $dir = (string) Config::get('paths.uploads');
        $active = is_file(self::illusPath($projectId)) ? md5_file(self::illusPath($projectId)) : null;
        $out = [];
        foreach (glob($dir . '/cover-lib-' . $projectId . '-*.jpg') ?: [] as $file) {
            if (!preg_match('/-([a-z0-9]+)\.jpg$/i', $file, $m)) {
                continue;
            }
            $out[] = [
                'slug'       => $m[1],
                'created_at' => (int) filemtime($file),
                'active'     => $active !== null && md5_file($file) === $active,
            ];
        }
        // Ordre stable : plus récent d'abord, départage par slug quand deux
        // créations partagent la même seconde (l'affichage ne « saute » jamais).
        usort($out, fn ($a, $b) => [$b['created_at'], $b['slug']] <=> [$a['created_at'], $a['slug']]);
        return $out;
    }

    /** Ajoute une image à la bibliothèque et la rend active. Retourne le slug. */
    public static function addToLibrary(int $projectId, string $jpegBinary): string
    {
        $slug = substr(bin2hex(random_bytes(6)), 0, 10);
        $file = self::illusItemPath($projectId, $slug);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($file, $jpegBinary);
        @copy($file, self::illusPath($projectId));
        return $slug;
    }

    public static function defaultPrompt(array $texts): string
    {
        $title = trim((string) ($texts['title'] ?? ''));
        return 'Illustration du thème « ' . $title . ' » : objet ou scène emblématique du sujet, '
            . 'composition centrée, fond uni.';
    }

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
        ob_start();
        imagejpeg($src, null, 94);
        $jpeg = (string) ob_get_clean();
        imagedestroy($src);
        // Chaque création REJOINT la bibliothèque du livre (rien n'est écrasé)
        // et devient l'illustration active.
        self::addToLibrary($projectId, $jpeg);
        return self::illusPath($projectId);
    }

    /**
     * Éléments de la 4ÈME de couverture (auteur + textes de vente), même
     * moteur que la 1ère. La zone code-barres KDP est réservée en blanc.
     */
    public static function backElements(array $palette, array $texts): array
    {
        $c1 = $palette['c1'] ?? '#1B2A4A';
        $c2 = $palette['c2'] ?? '#C4571F';
        $c3 = $palette['c3'] ?? '#F4EFE4';
        $tagline = trim((string) ($texts['tagline'] ?? ''));
        $back    = trim((string) ($texts['back_text'] ?? ''));
        $bio     = trim((string) ($texts['bio'] ?? ''));
        $author  = trim((string) ($texts['author'] ?? ''));

        // La bio ne commence jamais par le nom : il est affiché séparément
        if ($author !== '' && $bio !== '' && str_starts_with(mb_strtolower($bio), mb_strtolower($author))) {
            $bio = trim(mb_substr($bio, mb_strlen($author)), " \t\n—–-–.");
        }

        $els = [];
        $els[] = ['id' => 'fond', 'type' => 'rect', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'color' => $c1];
        $els[] = ['id' => 'filet', 'type' => 'rect', 'x' => 8, 'y' => 6.4, 'w' => 84, 'h' => 0.16, 'color' => $c2];
        if ($tagline !== '') {
            $els[] = ['id' => 'tagline', 'type' => 'text', 'text' => $tagline, 'x' => 8, 'y' => 8.4, 'w' => 84,
                      'size' => 3.2, 'font' => 'instrument-serif', 'italic' => true, 'weight' => 400,
                      'align' => 'left', 'color' => $c2, 'lh' => 1.25, 'maxLines' => 2, 'maxH' => 8];
        }
        if ($back !== '') {
            // Zone bornée : le texte s'auto-réduit pour ne JAMAIS déborder sur la bio
            $els[] = ['id' => 'back_text', 'type' => 'text', 'text' => $back, 'x' => 8, 'y' => 18, 'w' => 84,
                      'size' => 2.2, 'font' => 'instrument-sans', 'weight' => 400,
                      'align' => 'left', 'color' => $c3, 'lh' => 1.5, 'maxLines' => 0, 'maxH' => 44];
        }
        // Bloc auteur en bas à gauche, à l'écart de la zone code-barres (bas droit)
        $bioTop = 66.5;
        $els[] = ['id' => 'filet2', 'type' => 'rect', 'x' => 8, 'y' => $bioTop, 'w' => 10, 'h' => 0.16, 'color' => $c2];
        if ($author !== '') {
            $els[] = ['id' => 'author', 'type' => 'text', 'text' => mb_strtoupper($author), 'x' => 8, 'y' => $bioTop + 1.6,
                      'w' => 56, 'size' => 1.7, 'font' => 'plex-mono', 'weight' => 400, 'align' => 'left',
                      'color' => $c3, 'lh' => 1.3, 'maxLines' => 1];
        }
        if ($bio !== '') {
            $els[] = ['id' => 'bio', 'type' => 'text', 'text' => $bio, 'x' => 8, 'y' => $bioTop + ($author !== '' ? 4.6 : 1.8),
                      'w' => 56, 'size' => 1.8, 'font' => 'instrument-sans', 'weight' => 400,
                      'align' => 'left', 'color' => $c3, 'lh' => 1.45, 'maxLines' => 6, 'maxH' => 15];
        }
        return $els;
    }

    /**
     * Couleur de la tranche : celle du fond plein de la 1ère de couverture
     * (élément « fond » couvrant toute la face, y compris si l'utilisateur
     * l'a personnalisé dans l'éditeur), sinon la couleur c1 de la palette.
     * Garantit une tranche du MÊME aplat que la face adjacente : aucun
     * « débordement » visible au pli, même avec la variance d'impression KDP.
     */
    public static function spineHex(array $frontEls, array $palette): string
    {
        foreach ($frontEls as $el) {
            if (($el['type'] ?? '') === 'rect'
                && (float) ($el['x'] ?? 100) <= 0.5 && (float) ($el['y'] ?? 100) <= 0.5
                && (float) ($el['w'] ?? 0) >= 99 && (float) ($el['h'] ?? 0) >= 99) {
                return (string) ($el['color'] ?? ($palette['c1'] ?? '#1B2A4A'));
            }
        }
        return (string) ($palette['c1'] ?? '#1B2A4A');
    }

    /** Couleur de texte lisible sur la tranche (contraste automatique). */
    public static function spineTextHex(string $bgHex, array $palette): string
    {
        $hex = ltrim($bgHex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $r = (int) hexdec(substr($hex, 0, 2));
        $g = (int) hexdec(substr($hex, 2, 2));
        $b = (int) hexdec(substr($hex, 4, 2));
        $luma = 0.299 * $r + 0.587 * $g + 0.114 * $b;
        return $luma > 165
            ? (string) ($palette['c4'] ?? '#12203A')
            : (string) ($palette['c3'] ?? '#F4EFE4');
    }

    /**
     * Couverture broché complète — 4ème + tranche + 1ère en UNE image
     * 300 dpi, fond perdu compris : le gabarit exact attendu par KDP.
     *
     * @param array $geometry  Layout::geometry() du projet (mm + dos)
     */
    public static function wrapImage(array $frontEls, array $backEls, array $palette, array $texts, array $geometry, ?string $illustrationPath): \GdImage
    {
        $dpi = 300;
        $mmToPx = fn (float $mm): int => (int) round($mm / 25.4 * $dpi);

        $bleed = (float) $geometry['bleed_mm'];
        $trimW = (float) $geometry['w_mm'];
        $trimH = (float) $geometry['h_mm'];
        $spine = (float) $geometry['spine_mm'];

        $totalW = $mmToPx($bleed + $trimW + $spine + $trimW + $bleed);
        $totalH = $mmToPx($trimH + 2 * $bleed);
        $panelW = $mmToPx($trimW + $bleed);   // chaque face déborde dans le fond perdu extérieur
        $panelH = $totalH;
        $frontX = $totalW - $panelW;          // bord gauche exact de la 1ère de couverture
        $spineW = $mmToPx($spine);

        $spineHex = self::spineHex($frontEls, $palette);
        $wrap = imagecreatetruecolor($totalW, $totalH);
        $spineBg = self::alloc($wrap, $spineHex);
        imagefilledrectangle($wrap, 0, 0, $totalW, $totalH, $spineBg);

        // Faces composées DIRECTEMENT aux dimensions du panneau (aucun
        // recadrage : rien ne peut être rogné, ni titre ni texte de 4ème)
        $back = self::renderElements($backEls, null, $panelW, $panelH);
        imagecopy($wrap, $back, 0, 0, 0, 0, $panelW, $panelH);
        imagedestroy($back);

        $front = self::renderElements($frontEls, $illustrationPath, $panelW, $panelH);
        imagecopy($wrap, $front, $frontX, 0, 0, 0, $panelW, $panelH);
        imagedestroy($front);

        // Tranche : aplat STRICTEMENT uni couvrant tout l'espace entre les deux
        // faces (les arrondis mm→px ne peuvent laisser ni jour ni chevauchement),
        // dessiné APRÈS les faces pour que rien ne déborde dans cette zone.
        // AUCUN texte n'est peint ici : le titre et l'auteur de la tranche sont
        // ajoutés en VECTORIEL par-dessus dans le PDF final (export/cover-pdf),
        // pour une netteté parfaite et zéro résidu qui dépasse de l'aplat.
        $spineX = $panelW;
        imagefilledrectangle($wrap, $spineX, 0, max($spineX + $spineW, $frontX) - 1, $totalH, $spineBg);

        // Zone code-barres KDP : blanc, 50,8 × 30,5 mm, à 6,35 mm des bords de coupe de la 4ème
        $white = imagecolorallocate($wrap, 255, 255, 255);
        $bw = $mmToPx((float) Config::get('kdp.barcode_w_mm', 50.8));
        $bh = $mmToPx((float) Config::get('kdp.barcode_h_mm', 30.5));
        $bx = $mmToPx($bleed + $trimW - 6.35) - $bw;
        $by = $totalH - $mmToPx($bleed + 6.35) - $bh;
        imagefilledrectangle($wrap, $bx, $by, $bx + $bw, $by + $bh, $white);

        return $wrap;
    }

    /**
     * MOCKUP 3D marketing : le livre debout, légèrement tourné (cisaillement
     * affine : face + tranche + ombre portée) — PNG 1200×1200 prêt pour les
     * réseaux et le contenu A+.
     */
    public static function mockup3d(array $frontEls, array $palette, ?string $illustrationPath): string
    {
        $W = 1200;
        $H = 1200;
        $out = imagecreatetruecolor($W, $H);
        imagefill($out, 0, 0, imagecolorallocate($out, 244, 239, 228));

        $bh = 760;
        $bw = (int) round($bh * self::W / self::H);
        $spineW = 56;
        $shearSpine = 0.30;   // la tranche « part » vers l'arrière
        $shearFront = 0.045;  // la face se redresse légèrement

        $front = self::renderElements($frontEls, $illustrationPath);
        $frontScaled = imagescale($front, $bw, $bh, IMG_BICUBIC);
        imagedestroy($front);

        // Tranche : couleur du fond de la 1ère, assombrie (lumière de côté)
        [$sr, $sg, $sb] = array_map('hexdec', str_split(ltrim(self::spineHex($frontEls, $palette), '#'), 2));
        $spine = imagecreatetruecolor($spineW, $bh);
        imagefill($spine, 0, 0, imagecolorallocate($spine, (int) ($sr * 0.60), (int) ($sg * 0.60), (int) ($sb * 0.60)));

        $x0 = (int) (($W - $bw - $spineW) / 2);
        $y0 = (int) (($H - $bh) / 2) - 30;

        // Ombre portée douce
        $shadow = imagecreatetruecolor($W, $H);
        $key = imagecolorallocate($shadow, 255, 0, 255);
        imagefill($shadow, 0, 0, $key);
        imagecolortransparent($shadow, $key);
        imagefilledellipse($shadow, $x0 + (int) (($bw + $spineW) / 2) + 16, $y0 + $bh + 44, $bw + 150, 84, imagecolorallocate($shadow, 203, 195, 178));
        imagecopymerge($out, $shadow, 0, 0, 0, 0, $W, $H, 65);
        imagedestroy($shadow);

        // Cisaillement vertical manuel, colonne par colonne (fiable en GD) :
        // tranche inclinée vers le bas, face légèrement redressée.
        for ($x = 0; $x < $spineW; $x++) {
            imagecopy($out, $spine, $x0 + $x, $y0 + (int) round($x * $shearSpine), $x, 0, 1, $bh);
        }
        $frontY = $y0 + (int) round($spineW * $shearSpine);
        for ($x = 0; $x < $bw; $x++) {
            imagecopy($out, $frontScaled, $x0 + $spineW + $x, $frontY - (int) round($x * $shearFront), $x, 0, 1, $bh);
        }
        // Épaisseur des pages : fin liseré clair le long du bord droit
        $paper = imagecolorallocate($out, 250, 247, 240);
        for ($i = 1; $i <= 5; $i++) {
            imageline(
                $out,
                $x0 + $spineW + $bw + $i - 1, $frontY - (int) round($bw * $shearFront) + 4 + $i,
                $x0 + $spineW + $bw + $i - 1, $frontY - (int) round($bw * $shearFront) + $bh - 2 + $i,
                $paper
            );
        }
        imagedestroy($spine);
        imagedestroy($frontScaled);

        ob_start();
        imagepng($out, null, 6);
        imagedestroy($out);
        return (string) ob_get_clean();
    }

    /**
     * Visuels A+ Amazon aux dimensions standard, dans la charte de la
     * couverture : bannière 970×600 ou pavé bénéfice 300×300.
     */
    public static function aplusImage(array $palette, array $texts, string $type, array $aplus): string
    {
        $c1 = (string) ($palette['c1'] ?? '#1B2A4A');
        $c2 = (string) ($palette['c2'] ?? '#C4571F');
        $c3 = (string) ($palette['c3'] ?? '#F4EFE4');
        $serif = self::fontFile('instrument-serif');
        $mono = self::fontFile('plex-mono');

        if ($type === 'banner') {
            $W = 970;
            $H = 600;
            $im = imagecreatetruecolor($W, $H);
            imagefill($im, 0, 0, self::alloc($im, $c1));
            imagefilledrectangle($im, 0, $H - 14, $W, $H, self::alloc($im, $c2));
            self::drawMotif($im, 'blob', $W * 0.84, $H * 0.26, 90, self::alloc($im, $c2), self::alloc($im, $c3));
            $headline = (string) ($aplus['headline'] ?? ($texts['title'] ?? ''));
            $sub = (string) ($aplus['subheadline'] ?? ($texts['tagline'] ?? ''));
            $y = 210;
            foreach (self::wrapPath($serif, $headline, 52, $W - 200) as $line) {
                imagettftext($im, 52, 0, 100, $y, self::alloc($im, $c3), $serif, $line);
                $y += 68;
            }
            if ($sub !== '') {
                $y += 12;
                foreach (self::wrapPath($mono, mb_strtoupper($sub), 17, $W - 220) as $line) {
                    imagettftext($im, 17, 0, 100, $y, self::alloc($im, $c2), $mono, $line);
                    $y += 30;
                }
            }
        } else {
            // Pavé bénéfice 300×300 (sq1 / sq2 / sq3)
            $index = max(1, min(3, (int) substr($type, 2)));
            $benefit = (array) (($aplus['benefits'] ?? [])[$index - 1] ?? []);
            $bgs = [$c2, $c1, $c3];
            $fgs = [$c3, $c3, $c1];
            $motifs = ['etoile', 'soleil', 'vagues'];
            $W = 300;
            $H = 300;
            $im = imagecreatetruecolor($W, $H);
            imagefill($im, 0, 0, self::alloc($im, $bgs[$index - 1]));
            self::drawMotif($im, $motifs[($index - 1) % count($motifs)], $W / 2, 96, 52, self::alloc($im, $fgs[$index - 1]), self::alloc($im, $bgs[$index - 1]));
            $label = (string) ($benefit['title'] ?? ('Bénéfice ' . $index));
            $y = 196;
            foreach (array_slice(self::wrapPath($serif, $label, 21, $W - 50), 0, 3) as $line) {
                $lw = self::widthPath($serif, $line, 21);
                imagettftext($im, 21, 0, (int) (($W - $lw) / 2), $y, self::alloc($im, $fgs[$index - 1]), $serif, $line);
                $y += 30;
            }
        }
        ob_start();
        imagepng($im, null, 6);
        imagedestroy($im);
        return (string) ob_get_clean();
    }

    // ── Sorties ────────────────────────────────────────────────────────────

    public static function thumbnailFromElements(array $els, ?string $illustrationPath): string
    {
        $im = self::renderElements($els, $illustrationPath);
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

    public static function jpegFromElements(array $els, ?string $illustrationPath, int $quality = 92): string
    {
        $im = self::renderElements($els, $illustrationPath);
        ob_start();
        imagejpeg($im, null, $quality);
        $jpg = (string) ob_get_clean();
        imagedestroy($im);
        return $jpg;
    }

    /** PNG transparent d'un motif seul (aperçu dans l'éditeur navigateur). */
    public static function motifPng(string $type, string $c1, string $c2, int $size = 400): string
    {
        $im = imagecreatetruecolor($size, $size);
        imagesavealpha($im, true);
        imagealphablending($im, false);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagealphablending($im, true);
        self::drawMotif($im, $type, $size / 2, $size / 2, $size * 0.42, self::alloc($im, $c1), self::alloc($im, $c2));
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        imagedestroy($im);
        return $png;
    }

    /** Nettoie/valide une liste d'éléments venant du navigateur. */
    public static function sanitizeElements(array $els): array
    {
        $clean = [];
        foreach (array_slice($els, 0, 30) as $el) {
            if (!is_array($el)) {
                continue;
            }
            $type = (string) ($el['type'] ?? '');
            if (!in_array($type, ['rect', 'ellipse', 'poly', 'frame', 'motif', 'image', 'text'], true)) {
                continue;
            }
            $out = [
                'id'   => preg_replace('/[^a-z0-9_-]/i', '', (string) ($el['id'] ?? uniqid('el'))) ?: uniqid('el'),
                'type' => $type,
                'x'    => max(-50, min(150, (float) ($el['x'] ?? 0))),
                'y'    => max(-50, min(150, (float) ($el['y'] ?? 0))),
                'w'    => max(0.2, min(200, (float) ($el['w'] ?? 10))),
                'h'    => max(0.05, min(200, (float) ($el['h'] ?? 10))),
            ];
            $hex = fn ($v, $d) => preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $v) ? (string) $v : $d;
            $out['color'] = $hex($el['color'] ?? '', '#1B2A4A');
            if ($type === 'motif') {
                $out['motif'] = in_array($el['motif'] ?? '', self::MOTIFS, true) ? $el['motif'] : 'blob';
                $out['color2'] = $hex($el['color2'] ?? '', '#F4EFE4');
            }
            if ($type === 'poly') {
                $out['points'] = array_slice(array_map(
                    fn ($p) => [max(-50, min(150, (float) ($p[0] ?? 0))), max(-50, min(150, (float) ($p[1] ?? 0)))],
                    (array) ($el['points'] ?? [])
                ), 0, 12);
            }
            if ($type === 'frame') {
                $out['thick'] = max(0.1, min(3, (float) ($el['thick'] ?? 0.4)));
            }
            if ($type === 'image') {
                $out['round'] = !empty($el['round']);
            }
            if ($type === 'text') {
                $out['text']     = mb_substr(trim((string) ($el['text'] ?? '')), 0, 400);
                $out['font']     = isset(self::FONTS[$el['font'] ?? '']) ? (string) $el['font'] : 'instrument-serif';
                $out['size']     = max(1.0, min(15, (float) ($el['size'] ?? 5.5)));
                $out['weight']   = (int) ($el['weight'] ?? 400) >= 600 ? 700 : 400;
                $out['italic']   = !empty($el['italic']);
                $out['align']    = in_array($el['align'] ?? '', ['left', 'center', 'right'], true) ? $el['align'] : 'left';
                $out['lh']       = max(0.9, min(2, (float) ($el['lh'] ?? 1.18)));
                $out['maxLines'] = max(0, min(8, (int) ($el['maxLines'] ?? 0)));
                if (isset($el['maxH'])) {
                    $out['maxH'] = max(2, min(100, (float) $el['maxH']));
                }
            }
            $clean[] = $out;
        }
        return $clean;
    }

    // ── Primitives graphiques ──────────────────────────────────────────────

    private static function drawMotif(\GdImage $im, string $type, float $cx, float $cy, float $r, int $col, int $col2): void
    {
        $cx = (int) $cx; $cy = (int) $cy; $r = (int) $r;
        switch ($type) {
            case 'soleil':
                for ($a = 0; $a < 360; $a += 30) {
                    $rad = deg2rad($a);
                    self::thickLine($im, $cx + cos($rad) * $r * 1.25, $cy + sin($rad) * $r * 1.25, $cx + cos($rad) * $r * 1.6, $cy + sin($rad) * $r * 1.6, max(6, (int) ($r / 14)), $col);
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
                imagefilledellipse($im, $cx, $cy, (int) ($r * 2), (int) ($r * 0.9), $col);
                self::thickLine($im, $cx - $r, $cy, $cx + $r, $cy, max(5, (int) ($r / 16)), $col2);
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

    private static function drawCover(\GdImage $dst, \GdImage $src, int $x, int $y, int $w, int $h, bool $circle): void
    {
        $sw = imagesx($src);
        $sh = imagesy($src);
        $scale = max($w / $sw, $h / $sh);
        $tmp = imagecreatetruecolor($w, $h);
        imagecopyresampled($tmp, $src, 0, 0, (int) (($sw - $w / $scale) / 2), (int) (($sh - $h / $scale) / 2), $w, $h, (int) ($w / $scale), (int) ($h / $scale));
        if ($circle) {
            imagealphablending($tmp, false);
            imagesavealpha($tmp, true);
            $trans = imagecolorallocatealpha($tmp, 0, 0, 0, 127);
            $cx = $w / 2;
            $cy = $h / 2;
            $rx = $w / 2;
            $ry = $h / 2;
            for ($iy = 0; $iy < $h; $iy++) {
                for ($ix = 0; $ix < $w; $ix++) {
                    $dx = ($ix - $cx) / $rx;
                    $dy = ($iy - $cy) / $ry;
                    if ($dx * $dx + $dy * $dy > 1) {
                        imagesetpixel($tmp, $ix, $iy, $trans);
                    }
                }
            }
            imagealphablending($dst, true);
        }
        imagecopy($dst, $tmp, $x, $y, 0, 0, $w, $h);
        imagedestroy($tmp);
    }

    private static function wrapPath(string $fontPath, string $text, int $size, int $maxW): array
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $lines = [];
        $cur = '';
        foreach ($words as $w) {
            $try = $cur === '' ? $w : $cur . ' ' . $w;
            if (self::widthPath($fontPath, $try, $size) > $maxW && $cur !== '') {
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

    private static function widthPath(string $fontPath, string $text, int $size): int
    {
        $box = imagettfbbox($size, 0, $fontPath, $text);
        return abs($box[2] - $box[0]);
    }

    private static function thickLine(\GdImage $im, float $x1, float $y1, float $x2, float $y2, int $thick, int $color): void
    {
        imagesetthickness($im, $thick);
        imageline($im, (int) $x1, (int) $y1, (int) $x2, (int) $y2, $color);
        imagesetthickness($im, 1);
    }

    private static function alloc(\GdImage $im, string $hex): int
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return imagecolorallocate($im, (int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2)));
    }

    /** Chemin TTF d'une police (italique si demandé et disponible). */
    public static function fontFile(string $slug, bool $italic = false, bool $bold = false): string
    {
        $def = self::FONTS[$slug] ?? self::FONTS['instrument-serif'];
        $file = $italic && $def[2] !== null ? $def[2] : $def[1];
        // Graisse réelle demandée : fichier SemiBold statique quand il existe
        if ($bold && !$italic && isset($def[4]) && $def[4] !== null) {
            $file = $def[4];
        }
        return APP_ROOT . '/app/fonts/' . $file;
    }

    /** La famille possède-t-elle un fichier de graisse réel (vs faux gras) ? */
    public static function hasBoldFile(string $slug, bool $italic = false): bool
    {
        $def = self::FONTS[$slug] ?? self::FONTS['instrument-serif'];
        return !$italic && isset($def[4]) && $def[4] !== null;
    }
}
