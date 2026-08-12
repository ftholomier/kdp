<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * Génération serveur du PDF intérieur — 100 % PHP natif, zéro dépendance.
 *
 * Un writer PDF minimal (polices de base Times/Helvetica, WinAnsi, images
 * JPEG, compression Flate) compose le livre : pages liminaires, sommaire
 * paginé, chapitres sur belle page, texte justifié, marges en miroir avec
 * gouttière KDP, hauts de page et folios.
 *
 * Note : l'export « qualité studio » recommandé reste l'impression navigateur
 * (print.php) qui incorpore les polices du design ; ce PDF serveur est la
 * voie entièrement automatisée.
 */
final class PdfBook
{
    /** Thèmes de mise en page intérieure proposés à l'étape 07. */
    public const THEMES = [
        'editorial' => ['name' => 'Éditorial',    'desc' => 'Serif classique, filets fins — l\'esprit maison d\'édition.'],
        'moderne'   => ['name' => 'Contemporain', 'desc' => 'Titres Poppins, grands numéros de chapitre colorés.'],
        'magazine'  => ['name' => 'Magazine',     'desc' => 'Bandeaux pleine largeur, titres Bebas Neue impactants.'],
        'elegant'   => ['name' => 'Élégant',      'desc' => 'DM Serif centré, ornements minimaux, grande respiration.'],
        'premium'   => ['name' => 'Premium',      'desc' => 'Esprit grand magazine : colonnes variées, gros chiffres, aplats bord à bord.'],
        'premium2'  => ['name' => 'Premium doux', 'desc' => 'La variante tendre : angles arrondis, tons pastel, Montserrat, grande respiration.'],
    ];

    /**
     * Ingrédients de mise en page activables (cases à cocher de l'étape 07).
     * Tous actifs par défaut ; appliqués au moteur premium.
     */
    public const LAYOUT_OPTIONS = [
        'col1'       => ['name' => 'Sections pleine largeur (1 colonne)',      'desc' => 'Respiration éditoriale, lecture ample'],
        'col2'       => ['name' => 'Sections sur 2 colonnes',                  'desc' => 'Le rythme magazine classique'],
        'col3'       => ['name' => 'Sections sur 3 colonnes',                  'desc' => 'Colonnes serrées façon presse'],
        'lead'       => ['name' => 'Chapeau d\'ouverture',                     'desc' => 'Premier paragraphe en grand corps'],
        'bignum'     => ['name' => 'Chiffres clés géants',                     'desc' => 'Les :::chiffre en très grand corps accent'],
        'bands'      => ['name' => 'Bandeaux « À retenir »',                   'desc' => 'Pleine largeur, en aplat de couleur'],
        'callouts'   => ['name' => 'Encarts colorés',                          'desc' => 'Conseil, exemple, FAQ, attention'],
        'chapnum'    => ['name' => 'Grand numéro de chapitre',                 'desc' => 'Le 01/02 géant des ouvertures'],
        'openerband' => ['name' => 'Ouverture en aplat de couleur',            'desc' => 'Décochez pour une ouverture sobre sur blanc'],
        'sectionnum' => ['name' => 'Têtes de section composées',               'desc' => 'Pavés numérotés, gros titres, filets'],
        'figwide'    => ['name' => 'Photos pleine largeur',                    'desc' => 'Décochez pour des visuels plus discrets'],
        'deco'       => ['name' => 'Ornements & décalages',                    'desc' => 'Barres décoratives qui débordent des blocs'],
    ];

    /** Options effectives d'un projet (défauts + choix enregistrés). */
    public static function layoutOptions(array $project): array
    {
        $defaults = array_fill_keys(array_keys(self::LAYOUT_OPTIONS), true);
        $saved = json_decode((string) ($project['layout_options'] ?? ''), true);
        if (!is_array($saved)) {
            return $defaults;
        }
        foreach ($defaults as $key => $_) {
            if (array_key_exists($key, $saved)) {
                $defaults[$key] = (bool) $saved[$key];
            }
        }
        // Garde-fou : au moins un gabarit de colonnes actif
        if (!$defaults['col1'] && !$defaults['col2'] && !$defaults['col3']) {
            $defaults['col2'] = true;
        }
        return $defaults;
    }

    public static function build(array $project, array $book, string $theme = 'editorial', string $accent = '#C4571F'): string
    {
        if (!isset(self::THEMES[$theme])) {
            $theme = 'editorial';
        }
        $geometry = Layout::geometry($project);
        $composer = new PdfComposer($geometry, $book, $project, $theme, $accent);
        $pdf = $composer->compose();

        $dir = (string) Config::get('paths.exports');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $file = $dir . '/projet-' . (int) $project['id'] . '-interieur.pdf';
        file_put_contents($file, $pdf);
        return $file;
    }
}

/**
 * Compose le livre page par page au-dessus de MiniPdf.
 * Toutes les polices sont INCORPORÉES (TTF embarquées, exigence KDP) et la
 * mise en page suit le thème choisi, avec la couleur d'accent de la couverture.
 */
final class PdfComposer
{
    private MiniPdf $pdf;
    private float $w;
    private float $h;
    private float $top;
    private float $bottom;
    private float $inner;
    private float $outer;
    private int $pageNum = 0;
    private array $chapterStarts = [];
    private string $runningRecto = '';

    private array $accent;                 // RVB accent (couverture)
    private array $accentSoft;             // teinte claire de l'accent (fonds)
    private array $ink = [26, 26, 23];
    private array $gray = [110, 104, 92];

    private string $titleFont = 'body';    // police des titres selon thème
    private float $titleSize = 21.0;
    private string $opener = 'editorial';  // style d'ouverture de chapitre
    private array $opts = [];              // ingrédients de mise en page cochés

    // Composition en colonnes (thème premium : régions à 1, 2 ou 3 colonnes)
    private int $columns = 1;
    private float $colGap = 16.0;
    private int $col = 0;
    private float $colTop = 0.0;
    private float $regionMaxY = 0.0;   // profondeur maxi atteinte dans la région
    private int $premiumCols = 2;      // colonnes de la section premium en cours
    private float $balanceBottom = 0.0; // plancher d'équilibrage des colonnes (0 = plein bas de page)
    private float $regionContentH = 0.0; // hauteur totale mesurée de la région en cours
    private float $regionConsumed = 0.0; // hauteur déjà composée dans les colonnes closes
    /** @var array<int,array> encarts FLOTTÉS : reportés en tête de colonne suivante */
    private array $floatQueue = [];

    private const BODY_SIZE = 10.8;
    private const LEADING   = 15.4;
    private float $bodySize = self::BODY_SIZE;
    private float $leading = self::LEADING;

    public function __construct(
        private array $geometry,
        private array $book,
        private array $project,
        private string $theme,
        string $accentHex
    ) {
        $mm = fn (float $v): float => $v * 72 / 25.4;
        $this->w = $mm($geometry['w_mm']);
        $this->h = $mm($geometry['h_mm']);
        $this->top = $mm($geometry['margin_top_mm']);
        $this->bottom = $mm($geometry['margin_bottom_mm']);
        $this->inner = $mm($geometry['margin_inner_mm']);
        $this->outer = $mm($geometry['margin_outer_mm']);

        $this->accent = self::hexRgb($accentHex);
        $this->accentSoft = array_map(fn ($c) => (int) round($c + (255 - $c) * 0.88), $this->accent);
        $this->opts = PdfBook::layoutOptions($project);

        [$this->titleFont, $this->titleSize, $this->opener] = match ($theme) {
            'moderne'  => ['sans', 18.0, 'number'],
            'magazine' => ['display', 26.0, 'band'],
            'elegant'  => ['display2', 21.0, 'centered'],
            'premium'  => ['sans', 20.0, 'premium'],
            'premium2' => ['sans2', 20.0, 'premium2'],
            default    => ['body', 21.0, 'editorial'],
        };
        if ($this->isPremium()) {
            $this->bodySize = 9.4;     // densité magazine sur colonne étroite
            $this->leading = 13.2;
        }

        $this->pdf = $this->newPdf();
    }

    private function newPdf(): MiniPdf
    {
        $pdf = new MiniPdf($this->w, $this->h);
        $fonts = APP_ROOT . '/app/fonts/';
        $pdf->addTtf('body', $fonts . 'InstrumentSerif-Regular.ttf');
        $pdf->addTtf('italic', $fonts . 'InstrumentSerif-Italic.ttf');
        $pdf->addTtf('label', $fonts . 'IBMPlexMono-Medium.ttf');
        $pdf->addTtf('sans', $fonts . 'Poppins-SemiBold.ttf');
        if ($this->titleFont === 'display') {
            $pdf->addTtf('display', $fonts . 'BebasNeue.ttf');
        }
        if ($this->titleFont === 'display2') {
            $pdf->addTtf('display2', $fonts . 'DMSerifDisplay.ttf');
        }
        if ($this->titleFont === 'sans2') {
            // Premium doux : Montserrat, plus ronde et plus fun que Poppins
            $pdf->addTtf('sans2', $fonts . 'Montserrat-SemiBold.ttf');
            $pdf->addTtf('sans2r', $fonts . 'Montserrat-Regular.ttf');
        }
        return $pdf;
    }

    /** Les deux variantes premium partagent le même moteur de composition. */
    private function isPremium(): bool
    {
        return $this->opener === 'premium' || $this->opener === 'premium2';
    }

    /** Ingrédient de mise en page coché ? (composeur de l'étape 07) */
    private function opt(string $key): bool
    {
        return (bool) ($this->opts[$key] ?? true);
    }

    /** Police titres/numéros du moteur premium (Poppins ou Montserrat). */
    private function sansFont(): string
    {
        return $this->opener === 'premium2' ? 'sans2' : 'sans';
    }

    /** Police des kickers/étiquettes (mono éditorial, Montserrat en doux). */
    private function labelFont(): string
    {
        return $this->opener === 'premium2' ? 'sans2r' : 'label';
    }

    public function compose(): string
    {
        // 1) Corps composé d'abord (pages de départ des chapitres connues)
        $this->pdf = $this->newPdf();
        $bodyPdf = $this->pdf;
        $this->pageNum = 8;
        $this->composeBody();
        $bodyPages = $bodyPdf->detachPages();

        // 2) Pages liminaires avec le sommaire paginé, dans le MÊME document
        $frontPdf = $this->newPdf();
        $this->pdf = $frontPdf;
        $this->pageNum = 0;
        $this->composeFrontMatter();
        while ($this->pageNum < 8) {
            $this->newPage('blank');
        }
        $frontPdf->appendPages($bodyPages);
        if (($this->pageNum + count($bodyPages['pages'])) % 2 === 1) {
            $frontPdf->addBlankPage();
        }
        return $frontPdf->build();
    }

    // ── Pages liminaires ───────────────────────────────────────────────────

    private function composeFrontMatter(): void
    {
        $center = fn (float $y, string $font, float $size, string $text, ?array $rgb = null, float $tc = 0) =>
            $this->pdf->text(($this->w - $this->pdf->width($text, $font, $size, $tc)) / 2, $y, $font, $size, $text, 0, $tc, $rgb);

        // p.1 — faux-titre
        $this->newPage('blank');
        $center($this->h * 0.38, $this->titleFont, 14, $this->fitOneLine($this->book['title'], $this->titleFont, 14, $this->w * 0.8));

        // p.2 — blanche
        $this->newPage('blank');

        // p.3 — page de titre (composition selon thème)
        $this->newPage('blank');
        $titleLines = $this->wrapText($this->book['title'], $this->titleFont, 26, $this->w * 0.72);
        $y = $this->h * 0.30;
        switch ($this->opener) {
            case 'band':
                $this->pdf->rectRgb(0, $y - 46, $this->w, 8, $this->accent);
                break;
            case 'number':
                $this->pdf->rectRgb(($this->w - 26) / 2, $y - 48, 26, 26, $this->accent);
                break;
            case 'premium':
                // Aplat décalé partant du bord — signature magazine
                if ($this->opt('deco')) {
                    $this->pdf->rectRgb(0, $y - 58, $this->w * 0.36, 13, $this->accent);
                    $this->pdf->rectRgb($this->w * 0.36 + 6, $y - 58, 13, 13, $this->accentSoft);
                }
                break;
            case 'premium2':
                // Pastilles arrondies centrées — signature douce
                if ($this->opt('deco')) {
                    $this->pdf->roundRectRgb(($this->w - 64) / 2, $y - 56, 52, 10, 5, $this->accent);
                    $this->pdf->roundRectRgb(($this->w - 64) / 2 + 58, $y - 56, 10, 10, 5, $this->accentSoft);
                }
                break;
            case 'centered':
                $this->pdf->rectRgb(($this->w - 60) / 2, $y - 40, 60, 0.8, $this->ink);
                break;
            default:
                $this->pdf->rectRgb(($this->w - 44) / 2, $y - 42, 44, 2.4, $this->accent);
        }
        foreach ($titleLines as $line) {
            $center($y, $this->titleFont, 26, $line);
            $y += 32;
        }
        if ($this->book['subtitle'] !== '') {
            $y += 8;
            foreach ($this->wrapText($this->book['subtitle'], 'italic', 12.5, $this->w * 0.68) as $line) {
                $center($y, 'italic', 12.5, $line, $this->gray);
                $y += 17;
            }
        }
        $center($this->h * 0.82, 'label', 10, mb_strtoupper($this->book['author']), $this->ink, 1.6);

        // p.4 — copyright
        $this->newPage('blank');
        $lines = [
            '© ' . $this->book['year'] . ' ' . $this->book['author'] . '. Tous droits réservés.',
            'Aucune partie de ce livre ne peut être reproduite sans autorisation écrite.',
            'Publié en autoédition via Amazon Kindle Direct Publishing.',
        ];
        $y = $this->h - $this->bottom - 58;
        foreach ($lines as $line) {
            $this->pdf->text($this->marginLeft(), $y, 'body', 8.5, $line, 0, 0, $this->gray);
            $y += 13;
        }

        // p.5+ — sommaire avec points de conduite
        $this->newPage('blank');
        $center($this->top + 42, $this->titleFont, 20, 'Sommaire');
        if ($this->opener === 'editorial' || $this->opener === 'centered') {
            $this->pdf->rectRgb(($this->w - 36) / 2, $this->top + 54, 36, 1.4, $this->accent);
        } elseif ($this->opener === 'premium') {
            $this->pdf->rectRgb(($this->w - 44) / 2, $this->top + 52, 44, 4, $this->accent);
        } elseif ($this->opener === 'premium2') {
            $this->pdf->roundRectRgb(($this->w - 46) / 2, $this->top + 51, 46, 5, 2.5, $this->accent);
        }
        $y = $this->top + 92;
        foreach ($this->book['chapters'] as $chapter) {
            if ($y > $this->h - $this->bottom - 16) {
                $this->newPage('blank');
                $y = $this->top + 30;
            }
            $left = $this->marginLeft();
            $right = $this->w - $this->marginRight();
            $pageLabel = (string) ($this->chapterStarts[$chapter['num']] ?? '');
            $pageW = $this->pdf->width($pageLabel, 'label', 9.5);

            $prefix = '';
            if (($chapter['role'] ?? 'chapter') === 'chapter') {
                $prefix = str_pad((string) ($chapter['display_num'] ?? $chapter['num']), 2, '0', STR_PAD_LEFT);
                $this->pdf->text($left, $y, 'label', 9, $prefix, 0, 0, $this->accent);
            }
            $titleX = $left + 26;
            $titleText = $this->fitOneLine($chapter['title'], 'body', 11.5, $right - $titleX - $pageW - 34);
            $this->pdf->text($titleX, $y, 'body', 11.5, $titleText);

            // Points de conduite
            $dotsStart = $titleX + $this->pdf->width($titleText, 'body', 11.5) + 8;
            $dotsEnd = $right - $pageW - 8;
            if ($dotsEnd > $dotsStart + 10) {
                $dotW = max(0.1, $this->pdf->width('·', 'body', 9));
                $count = max(0, (int) floor(($dotsEnd - $dotsStart) / ($dotW * 2.2)));
                $this->pdf->text($dotsStart, $y, 'body', 9, str_repeat('· ', $count), 0, 0, [180, 171, 148]);
            }
            $this->pdf->text($right - $pageW, $y, 'label', 9.5, $pageLabel, 0, 0, $this->gray);
            $y += 21.5;
        }
    }

    // ── Corps ──────────────────────────────────────────────────────────────

    private function composeBody(): void
    {
        $premium = $this->isPremium();
        foreach ($this->book['chapters'] as $chapter) {
            $this->runningRecto = $chapter['title'];
            if ($this->pageNum % 2 === 1) {
                $this->newPage('blank');
            }
            $this->newPage('opener');
            $this->chapterStarts[$chapter['num']] = $this->pageNum;

            $this->columns = 1;
            $this->col = 0;
            $y = $this->chapterOpener($chapter);
            $this->colTop = $y;
            $this->regionMaxY = $y;
            $this->drawFolio();

            $imagesPlaced = false;
            foreach ($chapter['sections'] as $sIndex => $section) {
                $mode = $premium ? $this->premiumMode((int) $chapter['num'], $sIndex) : '';
                if ($premium) {
                    $this->premiumCols = match ($mode) { 'trio' => 3, 'solo' => 1, default => 2 };
                }
                if ($sIndex > 0) {
                    if ($premium) {
                        $y = $this->endRegion($y);
                        $y = $this->ensureRoom($y, 4 * $this->leading + 52);
                        $y = $this->premiumSectionHead($y, $section['title'], $sIndex, $mode);
                    } else {
                        $y = $this->ensureRoom($y, 3 * $this->leading + 34);
                        $y = $this->sectionHead($y, $section['title'], $sIndex);
                    }
                }
                $blocks = !empty($section['blocks'])
                    ? $section['blocks']
                    : array_map(fn ($p) => ['t' => 'p', 'text' => $p], $section['paragraphs'] ?? []);

                if ($premium) {
                    $y = $this->premiumSection($y, $blocks, $sIndex);
                } else {
                    foreach ($blocks as $bIndex => $block) {
                        $type = $block['t'] ?? 'p';
                        if ($type === 'call') {
                            $y = $this->callout($y, (string) $block['kind'], (string) $block['text']);
                        } elseif ($type === 'h') {
                            $y = $this->subHead($y, (string) $block['text']);
                        } elseif ($type === 'list') {
                            foreach ((array) $block['items'] as $item) {
                                $y = $this->paragraph($y, '– ' . $item, false);
                            }
                            $y += 3;
                        } else {
                            $y = $this->paragraph($y, (string) $block['text'], $bIndex > 0 || $sIndex > 0);
                        }
                    }
                }
                if (!$imagesPlaced && !empty($chapter['images'])) {
                    $imagesPlaced = true;
                    foreach ($chapter['images'] as $image) {
                        $y = $this->figure($y, $chapter['num'], $image);
                        if ($premium) {
                            $y = $this->gridSnap($y);
                        }
                    }
                }
            }
            if ($premium) {
                $y = $this->endRegion($y);
            }
        }
    }

    /**
     * Rythme éditorial du thème premium : chaque section reçoit un des trois
     * gabarits, décalé d'un chapitre à l'autre pour que deux chapitres
     * successifs ne se ressemblent jamais.
     *   feature → gros titre pleine largeur + 2 colonnes
     *   duo     → pavé numéroté + 2 colonnes
     *   trio    → titre filet + 3 colonnes serrées
     */
    private function premiumMode(int $chapterNum, int $sIndex): string
    {
        // Rotation construite à partir des gabarits COCHÉS dans le composeur
        $modes = [];
        if ($this->opt('col2')) {
            $modes[] = 'feature';
        }
        if ($this->opt('col1')) {
            $modes[] = 'solo';
        }
        if ($this->opt('col2')) {
            $modes[] = 'duo';
        }
        if ($this->opt('col3')) {
            $modes[] = 'trio';
        }
        if (!$modes) {
            $modes = ['feature', 'solo', 'duo', 'trio'];
        }
        return $modes[($chapterNum + $sIndex) % count($modes)];
    }

    /**
     * Corps d'une section premium : le contenu est découpé en segments —
     * les chiffres clés et « à retenir » sortent en PLEINE LARGEUR, le reste
     * coule en colonnes ÉQUILIBRÉES (hauteur mesurée puis répartie, comme le
     * ferait un maquettiste, pour ne jamais laisser une colonne vide).
     */
    private function premiumSection(float $y, array $blocks, int $sIndex): float
    {
        $segments = [];
        $flow = [];
        foreach ($blocks as $block) {
            $kind = (string) ($block['kind'] ?? '');
            $fullWidth = ($block['t'] ?? 'p') === 'call'
                && (($kind === 'chiffre' && $this->opt('bignum')) || ($kind === 'retenir' && $this->opt('bands')));
            if ($fullWidth) {
                if ($flow) {
                    $segments[] = ['flow', $flow];
                    $flow = [];
                }
                $segments[] = ['full', $block];
            } else {
                $flow[] = $block;
            }
        }
        if ($flow) {
            $segments[] = ['flow', $flow];
        }

        $firstSegment = true;
        foreach ($segments as $segment) {
            if ($segment[0] === 'full') {
                $y = $this->endRegion($y);
                $y = (string) $segment[1]['kind'] === 'chiffre'
                    ? $this->premiumStat($y, (string) $segment[1]['text'])
                    : $this->premiumBand($y, (string) $segment[1]['kind'], (string) $segment[1]['text']);
                $firstSegment = false;
                continue;
            }
            $run = $segment[1];
            // Chapeau : le tout premier paragraphe du chapitre, en grand
            if ($firstSegment && $sIndex === 0 && ($run[0]['t'] ?? 'p') === 'p' && $this->opt('lead')) {
                $lead = array_shift($run);
                $y = $this->premiumLead($y, (string) $lead['text']);
            }
            $firstSegment = false;
            if (!$run) {
                continue;
            }
            $y = $this->beginRegion($this->premiumCols, $y, $this->measureFlow($run));
            foreach ($run as $fIndex => $block) {
                $type = $block['t'] ?? 'p';
                if ($type === 'call') {
                    // Encart qui ne tient plus dans la colonne : FLOTTÉ en tête
                    // de la colonne suivante, le texte comble l'espace restant.
                    $boxH = $this->calloutHeight((string) $block['text']);
                    if ($this->opt('callouts') && $y + $boxH + 14 > $this->colBottom()
                        && $boxH < $this->h - $this->top - $this->bottom - 40) {
                        $this->floatQueue[] = $block;
                        continue;
                    }
                    $y = $this->gridSnap($this->callout($y, (string) $block['kind'], (string) $block['text']));
                } elseif ($type === 'h') {
                    $y = $this->gridSnap($this->subHead($y, (string) $block['text']));
                } elseif ($type === 'list') {
                    foreach ((array) $block['items'] as $item) {
                        $y = $this->paragraph($y, '– ' . $item, false);
                    }
                    $y = $this->gridSnap($y);
                } else {
                    $y = $this->paragraph($y, (string) $block['text'], $fIndex > 0);
                }
            }
            // Encarts encore en attente : posés avant de refermer la région
            while ($this->floatQueue) {
                $block = array_shift($this->floatQueue);
                $y = $this->gridSnap($this->callout($y, (string) $block['kind'], (string) $block['text']));
            }
            $y = $this->endRegion($y);
        }
        return $y;
    }

    /** Hauteur d'un encart premium à la largeur de colonne courante. */
    private function calloutHeight(string $text): float
    {
        $pad = 16.0;
        $innerW = $this->colWidth() - 2 * $pad;
        $n = 0;
        foreach (preg_split('/\n\s*\n/', trim($text)) ?: [] as $i => $paragraph) {
            if ($i > 0) {
                $n++;
            }
            $n += count($this->wrapText(trim($paragraph), 'body', 8.8, $innerW));
        }
        return 38 + $n * 12.6 + $pad * 0.7;
    }

    /** Hauteur estimée d'un flux de blocs composé en colonnes premium. */
    private function measureFlow(array $blocks): float
    {
        $cols = $this->premiumCols;
        $full = $this->textWidth();
        $colW = $cols > 1 ? ($full - $this->colGap * ($cols - 1)) / $cols : $full;
        [$size, $lead] = $cols >= 3 ? [8.6, 13.2] : [9.4, 13.2];
        $h = 0.0;
        foreach ($blocks as $block) {
            $type = $block['t'] ?? 'p';
            if ($type === 'call') {
                $pad = 16.0;
                $n = 0;
                foreach (preg_split('/\n\s*\n/', trim((string) $block['text'])) ?: [] as $i => $paragraph) {
                    if ($i > 0) {
                        $n++;
                    }
                    $n += count($this->wrapText(trim($paragraph), 'body', 8.8, $colW - 2 * $pad));
                }
                $h += 38 + $n * 12.6 + $pad * 0.7 + 15;
            } elseif ($type === 'h') {
                $h += 29.0;
            } elseif ($type === 'list') {
                foreach ((array) ($block['items'] ?? []) as $item) {
                    $h += count($this->justify('– ' . $item, 'body', $size, $colW, 0, 11.0)) * $lead;
                }
            } else {
                $h += count($this->justify((string) $block['text'], 'body', $size, $colW, 13.0, 0)) * $lead;
            }
        }
        return $h;
    }

    /** Ouverture de chapitre selon le thème. Retourne l'ordonnée du texte. */
    private function chapterOpener(array $chapter): float
    {
        $left = $this->marginLeft();
        $width = $this->textWidth();
        $label = mb_strtoupper((string) ($chapter['label'] ?? 'Chapitre'));
        $isChapter = ($chapter['role'] ?? 'chapter') === 'chapter';
        $displayNum = (string) ($chapter['display_num'] ?? '');

        switch ($this->opener) {
            case 'band':
                // Bandeau accent pleine largeur, titre réversé
                $bandH = 108.0;
                $this->pdf->rectRgb(0, 0, $this->w, $bandH, $this->accent);
                $this->pdf->text($left, 40, 'label', 8, $label, 0, 2.4, [255, 255, 255]);
                $ty = 70.0;
                foreach ($this->wrapText($chapter['title'], 'display', 25, $width) as $i => $line) {
                    if ($ty > $bandH - 10) {
                        break;
                    }
                    $this->pdf->text($left, $ty, 'display', 25, $line, 0, 0.6, [255, 255, 255]);
                    $ty += 28;
                }
                return $bandH + 30;

            case 'number':
                // Grand numéro accent + titre sans-serif
                $y = $this->top + 40;
                if ($isChapter && $displayNum !== '') {
                    $numText = str_pad($displayNum, 2, '0', STR_PAD_LEFT);
                    $numW = $this->pdf->width($numText, 'sans', 58);
                    $this->pdf->text($this->w - $this->marginRight() - $numW, $y + 18, 'sans', 58, $numText, 0, 0, $this->accentMid());
                }
                $this->pdf->text($left, $y, 'label', 8, $label, 0, 2.4, $this->gray);
                $y += 24;
                foreach ($this->wrapText($chapter['title'], 'sans', 19, $width - ($isChapter ? 80 : 0)) as $line) {
                    $this->pdf->text($left, $y, 'sans', 19, $line);
                    $y += 25;
                }
                $this->pdf->rectRgb($left, $y + 4, 34, 2.6, $this->accent);
                return $y + 30;

            case 'premium':
                if (!$this->opt('openerband')) {
                    return $this->soberOpener($chapter, $label, $isChapter, $displayNum);
                }
                // Grand aplat de couleur, numéro géant en tinte, GROS titre
                // réversé, barre décalée sous le bloc — alternée d'un chapitre
                // à l'autre pour varier le rythme des ouvertures.
                $bandH = max(186.0, $this->h * 0.285);
                $fg = $this->reverseInk();
                $this->pdf->rectRgb(0, 0, $this->w, $bandH, $this->accent);
                if ($isChapter && $displayNum !== '' && $this->opt('chapnum')) {
                    $numText = str_pad($displayNum, 2, '0', STR_PAD_LEFT);
                    $numW = $this->pdf->width($numText, 'sans', 66);
                    $this->pdf->text($this->w - $this->marginRight() - $numW, $bandH - 26, 'sans', 66, $numText, 0, 0, $this->accentMid());
                }
                $this->pdf->text($left, 54, 'label', 8, $label, 0, 2.6, $fg);
                $this->pdf->rectRgb($left, 66, 26, 2.2, $fg);
                $ty = 100.0;
                foreach ($this->wrapText($chapter['title'], 'sans', 22, $width * 0.66) as $line) {
                    if ($ty > $bandH - 18) {
                        break;
                    }
                    $this->pdf->text($left, $ty, 'sans', 22, $line, 0, 0, $fg);
                    $ty += 28;
                }
                // Décalage alterné : barre en tinte claire qui déborde du bloc
                if ($this->opt('deco')) {
                    $flip = $isChapter && $displayNum !== '' && ((int) $displayNum % 2 === 0);
                    if ($flip) {
                        $this->pdf->rectRgb($this->w * 0.56, $bandH + 12, $this->w * 0.44, 6.5, $this->accentSoft);
                    } else {
                        $this->pdf->rectRgb(0, $bandH + 12, $this->w * 0.44, 6.5, $this->accentSoft);
                    }
                }
                return $bandH + 46;

            case 'premium2':
                if (!$this->opt('openerband')) {
                    return $this->soberOpener($chapter, $label, $isChapter, $displayNum);
                }
                // Premium doux : panneau ARRONDI en tinte pastel inséré dans la
                // page, pastille numéro arrondie, titre à l'encre en Montserrat.
                $inset = 18.0;
                $bandH = max(196.0, $this->h * 0.29);
                $this->pdf->roundRectRgb($inset, $inset, $this->w - 2 * $inset, $bandH - $inset, 20.0, $this->accentSoft);
                if ($isChapter && $displayNum !== '' && $this->opt('chapnum')) {
                    $numText = str_pad($displayNum, 2, '0', STR_PAD_LEFT);
                    $chip = 56.0;
                    $chipX = $this->w - $this->marginRight() - $chip;
                    $chipY = $bandH - $chip - 16;
                    $this->pdf->roundRectRgb($chipX, $chipY, $chip, $chip, 16.0, $this->accent);
                    $numW = $this->pdf->width($numText, 'sans2', 24);
                    $this->pdf->text($chipX + ($chip - $numW) / 2, $chipY + $chip / 2 + 8.5, 'sans2', 24, $numText, 0, 0, $this->reverseInk());
                }
                $this->pdf->text($left + 8, 58, 'sans2r', 8, $label, 0, 2.6, $this->accentDark());
                $this->pdf->roundRectRgb($left + 8, 68, 30, 5, 2.5, $this->accent);
                $ty = 102.0;
                foreach ($this->wrapText($chapter['title'], 'sans2', 21, $width * 0.62) as $line) {
                    if ($ty > $bandH - 22) {
                        break;
                    }
                    $this->pdf->text($left + 8, $ty, 'sans2', 21, $line, 0, 0, $this->ink);
                    $ty += 27;
                }
                // Pastille douce décalée sous le panneau, côté alterné
                if ($this->opt('deco')) {
                    $flip2 = $isChapter && $displayNum !== '' && ((int) $displayNum % 2 === 0);
                    $pw = $this->w * 0.30;
                    $this->pdf->roundRectRgb($flip2 ? $this->w - $inset - $pw : $inset, $bandH + 14, $pw, 7, 3.5, $this->accentMid());
                }
                return $bandH + 48;

            case 'centered':
                // Composition centrée, filets fins
                $y = $this->top + 44;
                $this->centerText($y, 'label', 7.5, $label, $this->gray, 3.0);
                $y += 18;
                $this->pdf->rectRgb(($this->w - 46) / 2, $y, 46, 0.7, $this->ink);
                $y += 26;
                foreach ($this->wrapText($chapter['title'], 'display2', 21, $width * 0.86) as $line) {
                    $this->centerText($y, 'display2', 21, $line);
                    $y += 27;
                }
                $y += 6;
                $this->pdf->rectRgb(($this->w - 46) / 2, $y, 46, 0.7, $this->ink);
                return $y + 28;

            default:
                // Éditorial : kicker mono accent + filet + grand titre serif
                $y = $this->top + 46;
                $this->pdf->text($left, $y, 'label', 8, $label, 0, 2.4, $this->accent);
                $y += 12;
                $this->pdf->rectRgb($left, $y, 30, 2.2, $this->accent);
                $y += 26;
                foreach ($this->wrapText($chapter['title'], 'body', 21, $width) as $line) {
                    $this->pdf->text($left, $y, 'body', 21, $line);
                    $y += 26;
                }
                return $y + 16;
        }
    }

    private function sectionHead(float $y, string $title, int $num = 0): float
    {
        $left = $this->colX();
        $width = $this->colWidth();
        $y += 10;
        switch ($this->opener) {
            case 'band':
                $this->pdf->rectRgb($left, $y - 8, 3, 14, $this->accent);
                $this->pdf->text($left + 10, $y + 3, 'sans', 11.5, $this->fitOneLine($title, 'sans', 11.5, $width - 10));
                break;
            case 'number':
                $this->pdf->text($left, $y + 3, 'sans', 11.5, $this->fitOneLine($title, 'sans', 11.5, $width), 0, 0, $this->accentDark());
                break;
            case 'centered':
                $this->centerText($y + 3, 'display2', 12.5, $this->fitOneLine($title, 'display2', 12.5, $width * 0.9));
                break;
            default:
                $this->pdf->text($left, $y + 3, 'body', 13, $this->fitOneLine($title, 'body', 13, $width));
        }
        return $y + 24;
    }

    /**
     * Ouverture de chapitre SOBRE (aplat décoché dans le composeur) :
     * kicker, filet accent, grand titre à l'encre sur fond blanc.
     */
    private function soberOpener(array $chapter, string $label, bool $isChapter, string $displayNum): float
    {
        $left = $this->marginLeft();
        $sans = $this->sansFont();
        $y = $this->top + 48;
        $kicker = $label . ($isChapter && $displayNum !== '' && $this->opt('chapnum')
            ? '  ·  ' . str_pad($displayNum, 2, '0', STR_PAD_LEFT) : '');
        $this->pdf->text($left, $y, $this->labelFont(), 8, $kicker, 0, 2.6, $this->accentDark());
        $y += 12;
        if ($this->opener === 'premium2') {
            $this->pdf->roundRectRgb($left, $y, 30, 3.4, 1.7, $this->accent);
        } else {
            $this->pdf->rectRgb($left, $y, 30, 2.4, $this->accent);
        }
        $y += 28;
        foreach ($this->wrapText($chapter['title'], $sans, 22, $this->textWidth()) as $line) {
            $this->pdf->text($left, $y, $sans, 22, $line, 0, 0, $this->ink);
            $y += 28;
        }
        return $y + 18;
    }

    /** Têtes de section premium : quatre gabarits qui alternent (doux = arrondi). */
    private function premiumSectionHead(float $y, string $title, int $num, string $mode): float
    {
        $left = $this->marginLeft();
        $width = $this->textWidth();
        $sans = $this->sansFont();
        $lbl = $this->labelFont();
        $soft = $this->opener === 'premium2';
        $numText = str_pad((string) max(1, $num), 2, '0', STR_PAD_LEFT);

        // Têtes composées décochées : simple titre + petit filet accent
        if (!$this->opt('sectionnum')) {
            $y += 16;
            $t = $this->fitOneLine($title, $sans, 13, $width);
            $this->pdf->text($left, $y + 3, $sans, 13, $t);
            if ($soft) {
                $this->pdf->roundRectRgb($left, $y + 10, 20, 2.6, 1.3, $this->accent);
            } else {
                $this->pdf->rectRgb($left, $y + 10, 20, 1.8, $this->accent);
            }
            return $y + 26;
        }
        switch ($mode) {
            case 'feature':
            case 'solo':
                // GROS titre pleine largeur, barre accent entrant depuis le bord
                $y += 20;
                if ($soft) {
                    $this->pdf->roundRectRgb($left, $y - 6, 34, 7, 3.5, $this->accent);
                    $this->pdf->text($left + 44, $y + 1, $lbl, 7.5, 'SECTION ' . $numText, 0, 2.6, $this->accentDark());
                } else {
                    $this->pdf->rectRgb(0, $y - 7, max(10.0, $left - 9), 9, $this->accent);
                    $this->pdf->text($left, $y + 1, $lbl, 7.5, 'SECTION ' . $numText, 0, 2.6, $this->accentDark());
                }
                $y += 30;
                foreach ($this->wrapText($title, $sans, 17, $width) as $line) {
                    $this->pdf->text($left, $y, $sans, 17, $line);
                    $y += 22;
                }
                return $y + 12;

            case 'trio':
                // Titre compact + filet accent courant jusqu'à la marge
                $y += 16;
                if ($soft) {
                    $this->pdf->roundRectRgb($left, $y - 7.5, 9, 9, 4.5, $this->accent);
                } else {
                    $this->pdf->rectRgb($left, $y - 7.5, 8.5, 8.5, $this->accent);
                }
                $t = $this->fitOneLine($title, $sans, 11.5, $width * 0.72);
                $this->pdf->text($left + 16, $y + 0.5, $sans, 11.5, $t);
                $tEnd = $left + 16 + $this->pdf->width($t, $sans, 11.5);
                if ($tEnd + 14 < $left + $width) {
                    if ($soft) {
                        $this->pdf->roundRectRgb($tEnd + 10, $y - 3.6, $left + $width - $tEnd - 10, 2.4, 1.2, $this->accentSoft);
                    } else {
                        $this->pdf->rectRgb($tEnd + 10, $y - 2.8, $left + $width - $tEnd - 10, 0.9, $this->accent);
                    }
                }
                return $y + 24;

            default: // duo — pavé numéroté façon sommaire de magazine
                $y += 14;
                $sq = 16.0;
                if ($soft) {
                    $this->pdf->roundRectRgb($left, $y - 8.5, $sq, $sq, 5.0, $this->accent);
                } else {
                    $this->pdf->rectRgb($left, $y - 8.5, $sq, $sq, $this->accent);
                }
                $numW = $this->pdf->width($numText, $lbl, 7);
                $this->pdf->text($left + ($sq - $numW) / 2, $y + 2.5, $lbl, 7, $numText, 0, 0, $this->reverseInk());
                $t = $this->fitOneLine($title, $sans, 11.5, $width - $sq - 10);
                $this->pdf->text($left + $sq + 10, $y + 2.5, $sans, 11.5, $t);
                return $y + 28;
        }
    }

    /** Chapeau d'ouverture premium : grand corps pleine largeur, barre accent. */
    private function premiumLead(float $y, string $text): float
    {
        $size = 11.8;
        $lh = 17.4;
        $width = $this->textWidth() - 18;
        $lines = $this->justify($text, 'body', $size, $width, 0, 0);
        $blockH = count($lines) * $lh;
        if ($blockH > $this->h - $this->top - $this->bottom - 46) {
            return $this->paragraph($y, $text, false);
        }
        $y = $this->ensureRoom($y, $blockH + 18);
        $topY = $y;
        foreach ($lines as $line) {
            $this->pdf->text($this->marginLeft() + 18 + $line['x'], $y, 'body', $size, $line['text'], $line['tw']);
            $y += $lh;
        }
        $barTop = $topY - $size * 0.76;
        $barH = ($y - $lh + 3.5) - $barTop;
        if ($this->opener === 'premium2') {
            $this->pdf->roundRectRgb($this->marginLeft() + 2, $barTop, 4.2, $barH, 2.1, $this->accent);
        } else {
            $this->pdf->rectRgb($this->marginLeft() + 2, $barTop, 3.4, $barH, $this->accent);
        }
        return $y + 14;
    }

    /** Chiffre clé premium : GRAND nombre accent + commentaire en regard. */
    private function premiumStat(float $y, string $text): float
    {
        if (!preg_match('/(\d[\d\x{202F}\x{00A0} ,.]*\d|\d)\s*([%€$])?/u', $text, $m)) {
            return $this->callout($y, 'chiffre', $text);
        }
        $big = trim($m[1]) . (($m[2] ?? '') !== '' ? ' ' . $m[2] : '');
        $left = $this->marginLeft();
        $width = $this->textWidth();
        $sans = $this->sansFont();
        $size = 44.0;
        while ($size > 20 && $this->pdf->width($big, $sans, $size) > $width * 0.44) {
            $size *= 0.9;
        }
        $numW = $this->pdf->width($big, $sans, $size);
        $txtX = $left + max($numW, $width * 0.30) + 24;
        $txtW = $left + $width - $txtX;
        $lines = $this->wrapText($text, 'body', 9.4, $txtW);
        $blockH = max($size + 36, 28 + count($lines) * 12.8);
        if ($blockH > $this->h - $this->top - $this->bottom - 36) {
            return $this->callout($y, 'chiffre', $text);
        }
        $y = $this->ensureRoom($y, $blockH + 26);
        $y += 14;
        if ($this->opener === 'premium2') {
            $this->pdf->roundRectRgb($left, $y - 2, 30, 4, 2, $this->accent);
        } else {
            $this->pdf->rectRgb($left, $y - 2, 30, 2.6, $this->accent);
        }
        $this->pdf->text($left, $y + 13, $this->labelFont(), 7.2, 'CHIFFRE CLÉ', 0, 2.4, $this->accentDark());
        $this->pdf->text($left, $y + 18 + $size * 0.92, $sans, $size, $big, 0, 0, $this->accent);
        $ty = $y + 18;
        foreach ($lines as $line) {
            $this->pdf->text($txtX, $ty, 'body', 9.4, $line);
            $ty += 12.8;
        }
        return max($y + 24 + $size, $ty + 6) + 16;
    }

    /**
     * « À retenir » premium : bandeau PLEINE LARGEUR — réversé bord à bord en
     * Premium, panneau arrondi en tinte pastel en Premium doux.
     */
    private function premiumBand(float $y, string $kind, string $text): float
    {
        $labels = \App\Core\Util::CALLOUTS;
        $label = mb_strtoupper($labels[$kind] ?? $kind);
        $soft = $this->opener === 'premium2';
        $left = $this->marginLeft();
        $width = $this->textWidth();
        $pad = 20.0;                       // grands aplats = grande respiration
        $lineH = 13.8;
        $lines = [];
        foreach (preg_split('/\n\s*\n/', trim($text)) ?: [] as $i => $paragraph) {
            if ($i > 0) {
                $lines[] = '';
            }
            foreach ($this->wrapText(trim($paragraph), 'body', 9.8, $width - 2 * $pad) as $line) {
                $lines[] = $line;
            }
        }
        $boxH = 46 + count($lines) * $lineH + 10;
        if ($boxH > $this->h - $this->top - $this->bottom - 36) {
            return $this->callout($y, $kind, $text);
        }
        $y = $this->ensureRoom($y, $boxH + 22);
        $y += 10;
        if ($soft) {
            $fg = null;
            $labelFg = $this->accentDark();
            $this->pdf->roundRectRgb($left - 12, $y, $width + 24, $boxH, 14.0, $this->accentSoft);
        } else {
            $fg = $this->reverseInk();
            $labelFg = $fg;
            $this->pdf->rectRgb(0, $y, $this->w, $boxH, $this->accent);
        }
        $ty = $y + $pad + 4;
        $this->pdf->text($left + $pad, $ty, $this->labelFont(), 7.4, $label, 0, 2.6, $labelFg);
        $ty += 20;
        foreach ($lines as $line) {
            if ($line !== '') {
                $this->pdf->text($left + $pad, $ty, 'body', 9.8, $line, 0, 0, $fg);
            }
            $ty += $lineH;
        }
        return $y + $boxH + 18;
    }

    /** Sous-titre intra-section (bloc « h » : ancien ### markdown). */
    private function subHead(float $y, string $title): float
    {
        $y = $this->ensureRoom($y, 2 * $this->leading + 20);
        $y += 9;
        $left = $this->colX();
        $width = $this->colWidth();
        $font = in_array($this->opener, ['number', 'band'], true) ? 'sans' : ($this->isPremium() ? $this->sansFont() : 'body');
        $size = $this->isPremium() ? 10.0 : 11.0;
        $this->pdf->text($left, $y + 3, $font, $size, $this->fitOneLine($title, $font, $size, $width));
        if ($this->opener === 'premium2') {
            $this->pdf->roundRectRgb($left, $y + 8.5, 18, 2.6, 1.3, $this->accent);
        } elseif ($this->opener === 'premium') {
            $this->pdf->rectRgb($left, $y + 8.5, 17, 1.8, $this->accent);
        } elseif ($this->opener === 'editorial') {
            $this->pdf->rectRgb($left, $y + 8.5, 17, 1.2, $this->accent);
        }
        return $y + 20;
    }

    /** Écrit un paragraphe justifié, avec coupure de colonne/page automatique. */
    private function paragraph(float $y, string $text, bool $indent): float
    {
        $isList = str_starts_with(trim($text), '–') || str_starts_with(trim($text), '-') || str_starts_with(trim($text), '•');
        $width = $this->colWidth();
        $indentPt = $indent && !$isList ? 13.0 : 0.0;
        $lines = $this->justify($text, 'body', $this->bodySize, $width, $indentPt, $isList ? 11.0 : 0.0);

        foreach ($lines as $line) {
            if ($y > $this->colBottom() - 4) {
                $y = $this->breakColumn();
            }
            $x = $this->colX() + $line['x'];
            $this->pdf->text($x, $y, 'body', $this->bodySize, $line['text'], $line['tw']);
            $y += $this->leading;
        }
        // Premium : aucun blanc entre paragraphes (l'alinéa suffit), la grille
        // de lignes de base reste donc parfaitement alignée sur toute la page.
        $gap = $this->isPremium() ? 0.0 : 4.5;
        $this->regionMaxY = max($this->regionMaxY, $y);
        return $y + $gap;
    }

    /** Encadré éditorial : boîte teintée accent, étiquette, texte sans-serif. */
    private function callout(float $y, string $kind, string $text): float
    {
        $labels = \App\Core\Util::CALLOUTS;
        $label = mb_strtoupper($labels[$kind] ?? $kind);
        $premium = $this->isPremium();
        // Encarts décochés dans le composeur : simple paragraphe étiquette
        if ($premium && !$this->opt('callouts')) {
            return $this->paragraph($y, ($labels[$kind] ?? $kind) . ' — ' . str_replace("\n", ' ', $text), false);
        }
        $width = $this->colWidth();
        $pad = $premium ? 16.0 : 12.0;      // les aplats premium respirent
        $bodyFontSize = $premium ? 8.8 : 9.6;
        $innerW = $width - 2 * $pad - ($premium ? 0 : 6);

        $lines = [];
        foreach (preg_split('/\n\s*\n/', trim($text)) ?: [] as $i => $paragraph) {
            if ($i > 0) {
                $lines[] = '';
            }
            foreach ($this->wrapText(trim($paragraph), 'body', $bodyFontSize, $innerW) as $line) {
                $lines[] = $line;
            }
        }
        $lineH = $premium ? 12.6 : 13.4;
        $boxH = ($premium ? 38 : 30) + count($lines) * $lineH + $pad * 0.7;

        if ($boxH > $this->h - $this->top - $this->bottom - 20) {
            return $this->paragraph($y, ($labels[$kind] ?? $kind) . ' — ' . str_replace("\n", ' ', $text), false);
        }
        $y = $this->ensureRoom($y, $boxH + 10);
        $y += 4;
        $left = $this->colX();

        $fg = null;
        $labelFg = $this->accentDark();
        if ($this->opener === 'centered') {
            // Élégant : hairlines, pas de fond
            $this->pdf->rectRgb($left, $y, $width, 0.6, $this->ink);
            $this->pdf->rectRgb($left, $y + $boxH, $width, 0.6, $this->ink);
            $tx = $left + 2;
        } elseif ($this->opener === 'premium2') {
            // Premium doux : boîte ARRONDIE en tinte pastel, texte encre
            $this->pdf->roundRectRgb($left, $y, $width, $boxH, 10.0, $this->accentSoft);
            $tx = $left + $pad;
        } elseif ($premium) {
            // Premium : encart en APLAT accent, texte réversé — l'esprit magazine
            $this->pdf->rectRgb($left, $y, $width, $boxH, $this->accent);
            $fg = $this->reverseInk();
            $labelFg = $fg;
            $tx = $left + $pad;
        } else {
            $this->pdf->rectRgb($left, $y, $width, $boxH, $this->accentSoft);
            $this->pdf->rectRgb($left, $y, 3.4, $boxH, $this->accent);
            $tx = $left + $pad + 6;
        }
        $ty = $y + $pad + ($premium ? 1 : 3);
        $this->pdf->text($tx, $ty, $this->labelFont(), 7.2, $label, 0, 2.0, $labelFg);
        $ty += $premium ? 19 : 17;
        foreach ($lines as $line) {
            if ($line !== '') {
                $this->pdf->text($tx, $ty, 'body', $bodyFontSize, $line, 0, 0, $fg);
            }
            $ty += $lineH;
        }
        $this->regionMaxY = max($this->regionMaxY, $y + $boxH + 11);
        return $y + $boxH + 11;
    }

    /** Emplacement visuel : image JPEG incorporée ou cadre réservé légendé. */
    private function figure(float $y, int $chapterNum, array $image): float
    {
        $width = $this->colWidth();
        $left = $this->colX();
        // Photos discrètes (pleine largeur décochée) : 58 % de la justification
        if ($this->isPremium() && !$this->opt('figwide') && $this->columns <= 1) {
            $width = $this->textWidth() * 0.58;
            $left = $this->marginLeft() + ($this->textWidth() - $width) / 2;
        }
        $height = $width * 2 / 3;
        $y = $this->ensureRoom($y, $height + 40);
        $y += 8;

        $uploads = (string) Config::get('paths.uploads');
        $file = !empty($image['filename']) ? $uploads . '/' . $image['filename'] : null;
        if ($file && is_file($file)) {
            $name = $this->pdf->addJpeg($file);
            if ($name !== null) {
                $this->pdf->image($name, $left, $y, $width, $height);
            } else {
                $this->placeholderBox($y, $width, $height, $image, $left);
            }
        } else {
            $this->placeholderBox($y, $width, $height, $image, $left);
        }
        $y += $height + 14;
        $caption = 'Fig. ' . $chapterNum . '.' . $image['slot'] . ' — ' . ($image['caption'] ?: 'Visuel');
        $this->pdf->text($left, $y, 'italic', 9, $this->fitOneLine($caption, 'italic', 9, $width), 0, 0, $this->gray);
        $this->regionMaxY = max($this->regionMaxY, $y + 18);
        return $y + 18;
    }

    private function placeholderBox(float $y, float $width, float $height, array $image, ?float $left = null): void
    {
        $left ??= $this->colX();
        $this->pdf->rect($left, $y, $width, $height, 0.93);
        $label = 'Emplacement visuel — ' . ($image['spec'] ?: '300 dpi');
        $label = $this->fitOneLine($label, 'label', 8, $width - 20);
        $x = $left + ($width - $this->pdf->width($label, 'label', 8)) / 2;
        $this->pdf->text($x, $y + $height / 2, 'label', 8, $label, 0, 0, $this->gray);
    }

    // ── Habillage de page ──────────────────────────────────────────────────

    /** $kind : 'normal' (titre courant + folio), 'opener' (folio seul), 'blank' (rien). */
    private function newPage(string $kind = 'normal'): void
    {
        $this->pageNum++;
        $this->pdf->newPage();
        if ($this->pageNum >= 9 && $kind === 'normal') {
            $this->drawRunningHead();
            $this->drawFolio();
        }
    }

    private function drawRunningHead(): void
    {
        $isRecto = $this->pageNum % 2 === 1;
        $text = $isRecto ? ($this->runningRecto ?: $this->book['title']) : $this->book['title'];
        if ($this->opener === 'centered') {
            $text = $this->fitOneLine($text, 'italic', 8.5, $this->textWidth() * 0.8);
            $this->centerText($this->top - 14, 'italic', 8.5, $text, $this->gray);
            return;
        }
        $text = mb_strtoupper($this->fitOneLine($text, $this->labelFont(), 6.6, $this->textWidth() * 0.72));
        $x = $isRecto
            ? $this->w - $this->marginRight() - $this->pdf->width($text, $this->labelFont(), 6.6, 1.4)
            : $this->marginLeft();
        $this->pdf->text($x, $this->top - 14, $this->labelFont(), 6.6, $text, 0, 1.4, $this->gray);
    }

    private function drawFolio(): void
    {
        $folio = (string) $this->pageNum;
        if ($this->opener === 'centered') {
            $this->centerText($this->h - $this->bottom + 22, 'body', 9.5, $folio, $this->gray);
            return;
        }
        $isRecto = $this->pageNum % 2 === 1;
        $x = $isRecto
            ? $this->w - $this->marginRight() - $this->pdf->width($folio, 'label', 8.5)
            : $this->marginLeft();
        $this->pdf->text($x, $this->h - $this->bottom + 22, 'label', 8.5, $folio, 0, 0, $this->gray);
    }

    // ── Aides ──────────────────────────────────────────────────────────────

    private function centerText(float $y, string $font, float $size, string $text, ?array $rgb = null, float $tc = 0): void
    {
        $x = ($this->w - $this->pdf->width($text, $font, $size, $tc)) / 2;
        $this->pdf->text($x, $y, $font, $size, $text, 0, $tc, $rgb);
    }

    private function ensureRoom(float $y, float $needed): float
    {
        if ($y + $needed > $this->colBottom()) {
            return $this->breakColumn();
        }
        return $y;
    }

    /** Colonne suivante, ou page suivante quand la dernière colonne est pleine. */
    private function breakColumn(): float
    {
        // Hauteur réellement composée dans la colonne qui se referme
        $this->regionConsumed += max(0.0, $this->colBottom() - $this->colTop);
        if ($this->col < $this->columns - 1) {
            // Profondeur atteinte par la colonne close : son plancher réel
            $this->regionMaxY = max($this->regionMaxY, $this->colBottom());
            $this->col++;
            $y = $this->colTop;
        } else {
            $this->col = 0;
            $this->newPage();
            $this->colTop = $this->top + 16;
            $this->regionMaxY = $this->colTop;
            // Nouvelle page d'une région en cours : RÉ-ÉQUILIBRAGE avec le
            // reliquat mesuré — la dernière page d'une longue région retombe
            // sur des colonnes d'égale hauteur au lieu d'une colonne pleine
            // et d'une colonne moignon.
            $this->balanceBottom = 0.0;
            if ($this->columns > 1 && $this->regionContentH > 0) {
                $remaining = $this->regionContentH - $this->regionConsumed;
                if ($remaining > 0) {
                    $perCol = ceil($remaining / $this->columns / $this->leading) * $this->leading + 4;
                    if ($this->colTop + $perCol < $this->h - $this->bottom - $this->leading) {
                        $this->balanceBottom = $this->colTop + $perCol;
                    }
                }
            }
            $y = $this->colTop;
        }
        // Encarts flottés : posés en tête de la nouvelle colonne/page, le texte
        // reprend juste dessous — aucun trou laissé derrière.
        while ($this->floatQueue) {
            $block = array_shift($this->floatQueue);
            $y = $this->gridSnap($this->callout($y, (string) $block['kind'], (string) $block['text']));
        }
        return $y;
    }

    /**
     * Ouvre une région de composition à N colonnes à partir de l'ordonnée
     * donnée ; le corps et l'interligne s'adaptent à l'étroitesse des colonnes.
     */
    private function beginRegion(int $cols, float $y, float $contentH = 0.0): float
    {
        $this->columns = max(1, $cols);
        $this->col = 0;
        // Interligne UNIQUE (13,2 pt) quel que soit le nombre de colonnes :
        // toutes les lignes du livre retombent sur le même registre de page.
        [$this->bodySize, $this->leading] = match (true) {
            $this->columns >= 3 => [8.6, 13.2],
            $this->columns === 2 => [9.4, 13.2],
            default => [10.2, 13.2],
        };
        // Départ de région CALÉ sur la grille de la page
        $y = $this->gridSnap($y);
        $this->colTop = $y;
        $this->regionMaxY = $y;
        // Équilibrage : si le contenu tient dans la page, chaque colonne reçoit
        // la même hauteur (arrondie à la ligne) au lieu de tout empiler dans la
        // première.
        $this->balanceBottom = 0.0;
        $this->regionContentH = $contentH;
        $this->regionConsumed = 0.0;
        if ($this->columns > 1 && $contentH > 0) {
            $perCol = ceil($contentH / $this->columns / $this->leading) * $this->leading + 4;
            if ($y + $perCol < $this->h - $this->bottom - $this->leading) {
                $this->balanceBottom = $y + $perCol;
            }
        }
        return $y;
    }

    /**
     * Referme la région : repasse en pleine largeur SOUS la colonne la plus
     * profonde, pour que l'élément suivant reparte aligné bord à bord.
     */
    private function endRegion(float $y): float
    {
        $y = max($y, $this->regionMaxY);
        $this->columns = 1;
        $this->col = 0;
        $this->colTop = $this->top + 16;
        $this->regionMaxY = $y;
        $this->balanceBottom = 0.0;
        $this->regionContentH = 0.0;
        $this->regionConsumed = 0.0;
        [$this->bodySize, $this->leading] = [10.2, 13.2];
        return $y;
    }

    /** Plancher de la colonne courante (équilibré, sauf pour la dernière). */
    private function colBottom(): float
    {
        if ($this->balanceBottom > 0 && $this->col < $this->columns - 1) {
            return $this->balanceBottom;
        }
        return $this->h - $this->bottom;
    }

    /**
     * Aligne l'ordonnée sur la grille de lignes de base DE LA PAGE (et non de
     * la région) : colonnes voisines, pages successives et pages en vis-à-vis
     * retombent toutes sur le même registre — l'alignement d'imprimeur.
     */
    private function gridSnap(float $y): float
    {
        $base = $this->top + 16;
        if ($y <= $base) {
            return $base;
        }
        $n = (int) ceil(($y - $base) / $this->leading - 0.001);
        return $base + $n * $this->leading;
    }

    private function colWidth(): float
    {
        $full = $this->textWidth();
        return $this->columns > 1 ? ($full - $this->colGap * ($this->columns - 1)) / $this->columns : $full;
    }

    private function colX(): float
    {
        return $this->marginLeft() + $this->col * ($this->colWidth() + $this->colGap);
    }

    /** Couleur de texte lisible posée sur l'accent (réversé blanc ou encre). */
    private function reverseInk(): array
    {
        [$r, $g, $b] = $this->accent;
        return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 165 ? $this->ink : [255, 255, 255];
    }

    private function marginLeft(): float
    {
        return $this->pageNum % 2 === 1 ? $this->inner : $this->outer;
    }

    private function marginRight(): float
    {
        return $this->pageNum % 2 === 1 ? $this->outer : $this->inner;
    }

    private function textWidth(): float
    {
        return $this->w - $this->inner - $this->outer;
    }

    private function accentDark(): array
    {
        return array_map(fn ($c) => (int) round($c * 0.62), $this->accent);
    }

    private function accentMid(): array
    {
        return array_map(fn ($c) => (int) round($c + (255 - $c) * 0.45), $this->accent);
    }

    private static function hexRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            $hex = 'C4571F';
        }
        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }

    /** Coupe un texte en lignes justifiées : [{text,x,tw}] */
    private function justify(string $text, string $font, float $size, float $maxWidth, float $firstIndent, float $hang): array
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $lines = [];
        $current = [];
        $lineWidth = 0.0;
        $space = $this->pdf->width(' ', $font, $size);
        $avail = fn (int $lineIndex): float => $maxWidth - ($lineIndex === 0 ? $firstIndent : $hang);

        foreach ($words as $word) {
            $wordWidth = $this->pdf->width($word, $font, $size);
            $index = count($lines);
            if ($current && $lineWidth + $space + $wordWidth > $avail($index)) {
                $lines[] = ['words' => $current, 'width' => $lineWidth];
                $current = [];
                $lineWidth = 0.0;
            }
            $lineWidth += ($current ? $space : 0) + $wordWidth;
            $current[] = $word;
        }
        if ($current) {
            $lines[] = ['words' => $current, 'width' => $lineWidth, 'last' => true];
        }

        $out = [];
        foreach ($lines as $index => $line) {
            $spaces = count($line['words']) - 1;
            $isLast = !empty($line['last']);
            $available = $avail($index);
            $tw = (!$isLast && $spaces > 0) ? ($available - $line['width']) / $spaces : 0.0;
            $out[] = [
                'text' => implode(' ', $line['words']),
                'x'    => $index === 0 ? $firstIndent : $hang,
                'tw'   => max(0.0, min(5.5, $tw)),
            ];
        }
        return $out;
    }

    private function wrapText(string $text, string $font, float $size, float $maxWidth): array
    {
        return array_map(fn ($l) => $l['text'], $this->justify($text, $font, $size, $maxWidth, 0, 0));
    }

    private function fitOneLine(string $text, string $font, float $size, float $maxWidth): string
    {
        if ($this->pdf->width($text, $font, $size) <= $maxWidth) {
            return $text;
        }
        while (mb_strlen($text) > 3 && $this->pdf->width($text . '…', $font, $size) > $maxWidth) {
            $text = mb_substr($text, 0, -1);
        }
        return rtrim($text) . '…';
    }
}
/** Writer PDF bas niveau : polices de base + TTF INCORPORÉES, JPEG, Flate, RVB. */
final class MiniPdf
{
    private array $metrics;
    private array $fontIds = [];
    private array $pages = [];
    private string $current = '';
    private bool $open = false;
    private array $images = [];   // name => [data, w, h, colorspace]
    /** @var array<string,array{ttf:TtfFont,id:string}> polices TTF incorporées */
    private array $ttf = [];
    private int $fontCounter = 0;

    public function __construct(private float $w, private float $h)
    {
        $this->metrics = require APP_ROOT . '/app/Core/corefonts.php';
        foreach (array_keys($this->metrics) as $font) {
            $this->fontIds[$font] = 'F' . (++$this->fontCounter);
        }
    }

    /**
     * Déclare une police TTF incorporée sous un alias utilisable dans text().
     * La police est EMBARQUÉE intégralement dans le PDF (exigence KDP).
     */
    public function addTtf(string $alias, string $file): void
    {
        if (isset($this->ttf[$alias])) {
            return;
        }
        $this->ttf[$alias] = ['ttf' => TtfFont::load($file), 'id' => 'F' . (++$this->fontCounter)];
    }

    public function hasTtf(string $alias): bool
    {
        return isset($this->ttf[$alias]);
    }

    public function newPage(): void
    {
        if ($this->open) {
            $this->pages[] = $this->current;
        }
        $this->current = '';
        $this->open = true;
    }

    public function addBlankPage(): void
    {
        $this->newPage();
    }

    public function detachPages(): array
    {
        if ($this->open) {
            $this->pages[] = $this->current;
            $this->open = false;
        }
        $pages = $this->pages;
        $this->pages = [];
        return ['pages' => $pages, 'images' => $this->images];
    }

    public function appendPages(array $detached): void
    {
        if ($this->open) {
            $this->pages[] = $this->current;
            $this->open = false;
        }
        foreach ($detached['images'] as $name => $image) {
            $this->images[$name] = $image;
        }
        foreach ($detached['pages'] as $content) {
            $this->pages[] = $content;
        }
    }

    /** @var array<string,bool> polices de base réellement utilisées */
    private array $usedCore = [];

    /** y mesuré depuis le HAUT de la page (converti en interne). $rgb : [0-255,0-255,0-255]. */
    public function text(float $x, float $y, string $font, float $size, string $str, float $wordSpacing = 0, float $charSpacing = 0, ?array $rgb = null): void
    {
        if (!isset($this->ttf[$font]) && isset($this->fontIds[$font])) {
            $this->usedCore[$font] = true;
        }
        $yPdf = $this->h - $y;
        $color = $rgb
            ? sprintf('%.3F %.3F %.3F rg ', $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255)
            : '';
        if (isset($this->ttf[$font])) {
            // Police incorporée : texte encodé en identifiants de glyphes (Identity-H)
            $hex = $this->ttf[$font]['ttf']->gidHex($str);
            // L'espacement mot (Tw) est inopérant en Identity-H : simulé via TJ
            if ($wordSpacing > 0.01) {
                $spaceHex = $this->ttf[$font]['ttf']->gidHex(' ');
                $adjust = -$wordSpacing * 1000 / $size;
                $parts = explode($spaceHex, $hex);
                $tj = implode('> ' . sprintf('%.1F', $adjust) . ' <' . $spaceHex, $parts);
                $this->current .= sprintf(
                    "BT %s/%s %.2F Tf %.3F Tc %.2F %.2F Td [<%s>] TJ ET %s\n",
                    $color, $this->ttf[$font]['id'], $size, $charSpacing, $x, $yPdf, $tj, $rgb ? '0 0 0 rg' : ''
                );
            } else {
                $this->current .= sprintf(
                    "BT %s/%s %.2F Tf %.3F Tc %.2F %.2F Td <%s> Tj ET %s\n",
                    $color, $this->ttf[$font]['id'], $size, $charSpacing, $x, $yPdf, $hex, $rgb ? '0 0 0 rg' : ''
                );
            }
            return;
        }
        $id = $this->fontIds[$font] ?? 'F1';
        $encoded = $this->escape($this->toWinAnsi($str));
        $this->current .= sprintf(
            "BT %s/%s %.2F Tf %.3F Tw %.3F Tc %.2F %.2F Td (%s) Tj ET %s\n",
            $color, $id, $size, $wordSpacing, $charSpacing, $x, $yPdf, $encoded, $rgb ? '0 0 0 rg' : ''
        );
    }

    /**
     * Texte pivoté de 90° horaire (sens de lecture d'un dos de livre, de haut
     * en bas) — police TTF incorporée uniquement. (x, y) : départ de la ligne
     * de base, y mesuré depuis le haut ; le texte descend le long de la page.
     */
    public function vtext(float $x, float $y, string $font, float $size, string $str, float $charSpacing = 0, ?array $rgb = null): void
    {
        if (!isset($this->ttf[$font])) {
            return;
        }
        $yPdf = $this->h - $y;
        $color = $rgb
            ? sprintf('%.3F %.3F %.3F rg ', $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255)
            : '';
        $hex = $this->ttf[$font]['ttf']->gidHex($str);
        $this->current .= sprintf(
            "BT %s/%s %.2F Tf %.3F Tc 0 -1 1 0 %.2F %.2F Tm <%s> Tj ET %s\n",
            $color, $this->ttf[$font]['id'], $size, $charSpacing, $x, $yPdf, $hex, $rgb ? '0 0 0 rg' : ''
        );
    }

    public function rect(float $x, float $y, float $w, float $h, float $gray): void
    {
        $this->current .= sprintf("%.3F g %.2F %.2F %.2F %.2F re f 0 g\n", $gray, $x, $this->h - $y - $h, $w, $h);
    }

    /** Rectangle plein en couleur RVB. */
    public function rectRgb(float $x, float $y, float $w, float $h, array $rgb): void
    {
        $this->current .= sprintf(
            "%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f 0 g\n",
            $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255, $x, $this->h - $y - $h, $w, $h
        );
    }

    /** Rectangle à ANGLES ARRONDIS plein (courbes de Bézier), couleur RVB. */
    public function roundRectRgb(float $x, float $y, float $w, float $h, float $r, array $rgb): void
    {
        $r = min($r, $w / 2, $h / 2);
        if ($r < 0.5) {
            $this->rectRgb($x, $y, $w, $h, $rgb);
            return;
        }
        $k = 0.5523 * $r;
        $x0 = $x;
        $y0 = $this->h - $y - $h;   // coin bas-gauche en coordonnées PDF
        $x1 = $x + $w;
        $y1 = $this->h - $y;
        $f = fn (float $v): string => sprintf('%.2F', $v);
        $this->current .= sprintf('%.3F %.3F %.3F rg ', $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255)
            . $f($x0 + $r) . ' ' . $f($y0) . " m "
            . $f($x1 - $r) . ' ' . $f($y0) . " l "
            . $f($x1 - $r + $k) . ' ' . $f($y0) . ' ' . $f($x1) . ' ' . $f($y0 + $r - $k) . ' ' . $f($x1) . ' ' . $f($y0 + $r) . " c "
            . $f($x1) . ' ' . $f($y1 - $r) . " l "
            . $f($x1) . ' ' . $f($y1 - $r + $k) . ' ' . $f($x1 - $r + $k) . ' ' . $f($y1) . ' ' . $f($x1 - $r) . ' ' . $f($y1) . " c "
            . $f($x0 + $r) . ' ' . $f($y1) . " l "
            . $f($x0 + $r - $k) . ' ' . $f($y1) . ' ' . $f($x0) . ' ' . $f($y1 - $r + $k) . ' ' . $f($x0) . ' ' . $f($y1 - $r) . " c "
            . $f($x0) . ' ' . $f($y0 + $r) . " l "
            . $f($x0) . ' ' . $f($y0 + $r - $k) . ' ' . $f($x0 + $r - $k) . ' ' . $f($y0) . ' ' . $f($x0 + $r) . ' ' . $f($y0) . " c "
            . "f 0 g\n";
    }

    public function image(string $name, float $x, float $y, float $w, float $h): void
    {
        $this->current .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n", $w, $h, $x, $this->h - $y - $h, $name);
    }

    public function addJpeg(string $file): ?string
    {
        $info = @getimagesize($file);
        if (!$info || $info[2] !== IMAGETYPE_JPEG) {
            return null;
        }
        $name = 'Im' . (count($this->images) + 1);
        $this->images[$name] = [
            'data' => (string) file_get_contents($file),
            'w'    => $info[0],
            'h'    => $info[1],
            'cs'   => ($info['channels'] ?? 3) === 1 ? 'DeviceGray' : 'DeviceRGB',
        ];
        return $name;
    }

    /** Largeur d'une chaîne en points. */
    public function width(string $str, string $font, float $size, float $charSpacing = 0): float
    {
        if (isset($this->ttf[$font])) {
            $chars = max(0, mb_strlen($str) - 1);
            return $this->ttf[$font]['ttf']->widthMilli($str) * $size / 1000 + $charSpacing * $chars;
        }
        $widths = $this->metrics[$font] ?? $this->metrics['Times-Roman'];
        $encoded = $this->toWinAnsi($str);
        $total = 0;
        $len = strlen($encoded);
        for ($i = 0; $i < $len; $i++) {
            $total += $widths[ord($encoded[$i])];
        }
        return $total * $size / 1000 + $charSpacing * max(0, $len - 1);
    }

    public function build(): string
    {
        if ($this->open) {
            $this->pages[] = $this->current;
            $this->open = false;
        }

        $objects = [];
        $add = function (string $body) use (&$objects): int {
            $objects[] = $body;
            return count($objects);
        };

        // Polices de base : uniquement celles réellement utilisées (un livre
        // composé en polices incorporées n'en déclare aucune → conformité KDP)
        $fontRefs = [];
        foreach ($this->fontIds as $name => $id) {
            if (empty($this->usedCore[$name])) {
                continue;
            }
            $num = $add("<< /Type /Font /Subtype /Type1 /BaseFont /$name /Encoding /WinAnsiEncoding >>");
            $fontRefs[$id] = $num;
        }

        // Polices TTF INCORPORÉES (Type0 / CIDFontType2 / Identity-H)
        foreach ($this->ttf as $entry) {
            $ttf = $entry['ttf'];
            $raw = (string) file_get_contents($ttf->file);
            $stream = function_exists('gzcompress') ? gzcompress($raw) : $raw;
            $filter = function_exists('gzcompress') ? ' /Filter /FlateDecode' : '';
            $fileNum = $add('<< /Length ' . strlen($stream) . ' /Length1 ' . strlen($raw) . "$filter >>\nstream\n" . $stream . "\nendstream");

            $scale = 1000 / $ttf->unitsPerEm;
            $bbox = implode(' ', array_map(fn ($v) => (int) round($v * $scale), $ttf->bbox));
            $descNum = $add('<< /Type /FontDescriptor /FontName /' . $ttf->postScriptName
                . ' /Flags 4 /FontBBox [' . $bbox . ']'
                . ' /ItalicAngle ' . sprintf('%.1F', $ttf->italicAngle)
                . ' /Ascent ' . (int) round($ttf->ascent * $scale)
                . ' /Descent ' . (int) round($ttf->descent * $scale)
                . ' /CapHeight ' . (int) round($ttf->capHeight * $scale)
                . ' /StemV 80 /FontFile2 ' . $fileNum . ' 0 R >>');

            $widths = [];
            for ($gid = 0; $gid < $ttf->numGlyphs; $gid++) {
                $widths[] = (int) round(($ttf->advances[$gid] ?? 500) * $scale);
            }
            $cidNum = $add('<< /Type /Font /Subtype /CIDFontType2 /BaseFont /' . $ttf->postScriptName
                . ' /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >>'
                . ' /FontDescriptor ' . $descNum . ' 0 R /DW 500 /W [0 [' . implode(' ', $widths) . ']]'
                . ' /CIDToGIDMap /Identity >>');

            $typeZero = $add('<< /Type /Font /Subtype /Type0 /BaseFont /' . $ttf->postScriptName
                . ' /Encoding /Identity-H /DescendantFonts [' . $cidNum . ' 0 R] >>');
            $fontRefs[$entry['id']] = $typeZero;
        }
        // Images
        $imageRefs = [];
        foreach ($this->images as $name => $image) {
            $num = $add(
                "<< /Type /XObject /Subtype /Image /Width {$image['w']} /Height {$image['h']} "
                . "/ColorSpace /{$image['cs']} /BitsPerComponent 8 /Filter /DCTDecode "
                . '/Length ' . strlen($image['data']) . " >>\nstream\n" . $image['data'] . "\nendstream"
            );
            $imageRefs[$name] = $num;
        }

        $fontDict = '';
        foreach ($fontRefs as $id => $num) {
            $fontDict .= "/$id $num 0 R ";
        }
        $xobjDict = '';
        foreach ($imageRefs as $name => $num) {
            $xobjDict .= "/$name $num 0 R ";
        }
        $resources = $add("<< /Font << $fontDict>> " . ($xobjDict !== '' ? "/XObject << $xobjDict>> " : '') . '>>');

        $useFlate = function_exists('gzcompress');
        $pageObjectNumbers = [];
        $contentNumbers = [];
        foreach ($this->pages as $content) {
            $stream = $useFlate ? gzcompress($content) : $content;
            $filter = $useFlate ? ' /Filter /FlateDecode' : '';
            $contentNumbers[] = $add('<< /Length ' . strlen($stream) . "$filter >>\nstream\n" . $stream . "\nendstream");
        }

        $pagesNodeNum = count($objects) + count($this->pages) + 1;
        foreach ($contentNumbers as $contentNum) {
            $pageObjectNumbers[] = $add(
                "<< /Type /Page /Parent $pagesNodeNum 0 R /MediaBox [0 0 " . sprintf('%.2F %.2F', $this->w, $this->h)
                . "] /Resources $resources 0 R /Contents $contentNum 0 R >>"
            );
        }
        $kids = implode(' ', array_map(fn ($n) => "$n 0 R", $pageObjectNumbers));
        $pagesNode = $add("<< /Type /Pages /Kids [$kids] /Count " . count($pageObjectNumbers) . ' >>');
        $catalog = $add("<< /Type /Catalog /Pages $pagesNode 0 R >>");
        $info = $add('<< /Producer (Tirage KDP Studio) /CreationDate (D:' . date('YmdHis') . ') >>');

        // Assemblage avec xref
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $index => $body) {
            $offsets[$index + 1] = strlen($out);
            $out .= ($index + 1) . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xrefPos = strlen($out);
        $count = count($objects) + 1;
        $out .= "xref\n0 $count\n0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $out .= "trailer\n<< /Size $count /Root $catalog 0 R /Info $info 0 R >>\nstartxref\n$xrefPos\n%%EOF";
        return $out;
    }

    private function toWinAnsi(string $utf8): string
    {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $utf8);
        return $converted === false ? (string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $utf8) : $converted;
    }

    private function escape(string $str): string
    {
        return str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', ''], $str);
    }
}
