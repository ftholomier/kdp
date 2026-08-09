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
    public static function build(array $project, array $book): string
    {
        $geometry = Layout::geometry($project);
        $composer = new PdfComposer($geometry, $book, $project);
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

/** Compose le livre page par page au-dessus de MiniPdf. */
final class PdfComposer
{
    private MiniPdf $pdf;
    private float $w;               // largeur page (pt)
    private float $h;               // hauteur page (pt)
    private float $top;
    private float $bottom;
    private float $inner;
    private float $outer;
    private int $pageNum = 0;       // numéro logique courant (1 = faux-titre)
    private array $chapterStarts = [];
    private string $runningRecto = '';

    private const BODY_SIZE = 11.2;
    private const LEADING   = 15.9;
    private const BODY_FONT = 'Times-Roman';

    public function __construct(private array $geometry, private array $book, private array $project)
    {
        $mm = fn (float $v): float => $v * 72 / 25.4;
        $this->w = $mm($geometry['w_mm']);
        $this->h = $mm($geometry['h_mm']);
        $this->top = $mm($geometry['margin_top_mm']);
        $this->bottom = $mm($geometry['margin_bottom_mm']);
        $this->inner = $mm($geometry['margin_inner_mm']);
        $this->outer = $mm($geometry['margin_outer_mm']);
        $this->pdf = new MiniPdf($this->w, $this->h);
    }

    public function compose(): string
    {
        // 1) Corps composé d'abord (pour connaître les pages de départ des chapitres)
        $bodyPdf = new MiniPdf($this->w, $this->h);
        $this->pdf = $bodyPdf;
        $this->pageNum = 8; // le corps démarre p.9 (8 pages liminaires réservées)
        $this->composeBody();
        $bodyPages = $bodyPdf->detachPages();

        // 2) Pages liminaires avec le sommaire désormais paginé
        $frontPdf = new MiniPdf($this->w, $this->h);
        $this->pdf = $frontPdf;
        $this->pageNum = 0;
        $this->composeFrontMatter();
        while ($this->pageNum < 8) {
            $this->newPage(); // compléments blancs jusqu'à p.8
        }

        $frontPdf->appendPages($bodyPages);
        if (($this->pageNum + count($bodyPages)) % 2 === 1) {
            $frontPdf->addBlankPage();
        }
        return $frontPdf->build();
    }

    // ── Pages liminaires ───────────────────────────────────────────────────

    private function composeFrontMatter(): void
    {
        $center = fn (float $y, string $font, float $size, string $text) =>
            $this->pdf->text(($this->w - $this->pdf->width($text, $font, $size)) / 2, $y, $font, $size, $text);

        // p.1 — faux-titre
        $this->newPage();
        $center($this->h * 0.38, 'Times-Roman', 15, $this->book['title']);

        // p.2 — blanche
        $this->newPage();

        // p.3 — page de titre
        $this->newPage();
        $y = $this->h * 0.30;
        foreach ($this->wrapCentered($this->book['title'], 'Times-Roman', 26, $this->w * 0.72) as $line) {
            $center($y, 'Times-Roman', 26, $line);
            $y += 32;
        }
        if ($this->book['subtitle'] !== '') {
            $y += 6;
            foreach ($this->wrapCentered($this->book['subtitle'], 'Times-Italic', 13, $this->w * 0.7) as $line) {
                $center($y, 'Times-Italic', 13, $line);
                $y += 17;
            }
        }
        $center($y + 30, 'Helvetica', 11, mb_strtoupper($this->book['author']));

        // p.4 — copyright
        $this->newPage();
        $lines = [
            '© ' . $this->book['year'] . ' ' . $this->book['author'] . '. Tous droits réservés.',
            'Aucune partie de ce livre ne peut être reproduite sans autorisation écrite.',
            'Publié en autoédition via Amazon Kindle Direct Publishing.',
        ];
        $y = $this->h - $this->bottom - 60;
        foreach ($lines as $line) {
            $this->pdf->text($this->marginLeft(), $y, 'Times-Roman', 9, $line);
            $y += 13;
        }

        // p.5-6 — sommaire
        $this->newPage();
        $center($this->top + 40, 'Times-Roman', 22, 'Sommaire');
        $y = $this->top + 90;
        $left = $this->marginLeft();
        $right = $this->w - $this->marginRight();
        foreach ($this->book['chapters'] as $index => $chapter) {
            if ($y > $this->h - $this->bottom - 20) {
                $this->newPage();
                $y = $this->top + 30;
                $left = $this->marginLeft();
                $right = $this->w - $this->marginRight();
            }
            $label = ($index + 1) . '.  ' . $chapter['title'];
            $pageLabel = (string) ($this->chapterStarts[$chapter['num']] ?? '');
            $this->pdf->text($left, $y, 'Times-Roman', 11.5, $this->truncate($label, 'Times-Roman', 11.5, $right - $left - 40));
            $this->pdf->text($right - $this->pdf->width($pageLabel, 'Times-Roman', 11.5), $y, 'Times-Roman', 11.5, $pageLabel);
            $y += 21;
        }
    }

    // ── Corps ──────────────────────────────────────────────────────────────

    private function composeBody(): void
    {
        foreach ($this->book['chapters'] as $chapter) {
            $this->runningRecto = $chapter['title'];
            // Belle page : les chapitres ouvrent sur une page impaire (recto)
            if ($this->pageNum % 2 === 1) {
                $this->newPage('blank');
            }
            $this->newPage('opener');
            $this->chapterStarts[$chapter['num']] = $this->pageNum;

            $left = $this->marginLeft();
            $width = $this->textWidth();
            $y = $this->top + 46;

            $this->pdf->text($left, $y, 'Helvetica', 8.5, mb_strtoupper('Chapitre ' . $chapter['num']), 0, 2.2);
            $y += 26;
            foreach ($this->wrap($chapter['title'], 'Times-Roman', 21, $width) as $line) {
                $this->pdf->text($left, $y, 'Times-Roman', 21, $line);
                $y += 26;
            }
            $y += 18;
            $this->drawFolio();

            $imagesPlaced = false;
            foreach ($chapter['sections'] as $sIndex => $section) {
                if ($sIndex > 0) {
                    $y = $this->ensureRoom($y, 3 * self::LEADING + 30);
                    $y += 12;
                    $this->pdf->text($this->marginLeft(), $y, 'Times-Bold', 12.5, $this->truncate($section['title'], 'Times-Bold', 12.5, $this->textWidth()));
                    $y += 22;
                }
                foreach ($section['paragraphs'] as $pIndex => $paragraph) {
                    $y = $this->paragraph($y, $paragraph, $pIndex > 0 || $sIndex > 0);
                }
                if (!$imagesPlaced && !empty($chapter['images'])) {
                    $imagesPlaced = true;
                    foreach ($chapter['images'] as $image) {
                        $y = $this->figure($y, $chapter['num'], $image);
                    }
                }
            }
        }
    }

    /** Écrit un paragraphe justifié, avec coupure de page automatique. */
    private function paragraph(float $y, string $text, bool $indent): float
    {
        $isList = str_starts_with(trim($text), '–') || str_starts_with(trim($text), '-') || str_starts_with(trim($text), '•');
        $width = $this->textWidth();
        $indentPt = $indent && !$isList ? 14.0 : 0.0;
        $lines = $this->justify($text, self::BODY_FONT, self::BODY_SIZE, $width, $indentPt, $isList ? 12.0 : 0.0);

        foreach ($lines as $i => $line) {
            if ($y > $this->h - $this->bottom - 4) {
                $this->newPage();
                $y = $this->top + 16;
            }
            $x = $this->marginLeft() + $line['x'];
            $this->pdf->text($x, $y, self::BODY_FONT, self::BODY_SIZE, $line['text'], $line['tw']);
            $y += self::LEADING;
        }
        return $y + 5;
    }

    /** Emplacement visuel : image JPEG incorporée ou cadre réservé légendé. */
    private function figure(float $y, int $chapterNum, array $image): float
    {
        $width = $this->textWidth();
        $height = $width * 2 / 3;
        $needed = $height + 40;
        $y = $this->ensureRoom($y, $needed);
        $y += 8;

        $uploads = (string) Config::get('paths.uploads');
        $file = !empty($image['filename']) ? $uploads . '/' . $image['filename'] : null;
        if ($file && is_file($file)) {
            $name = $this->pdf->addJpeg($file);
            if ($name !== null) {
                $this->pdf->image($name, $this->marginLeft(), $y, $width, $height);
            } else {
                $this->placeholderBox($y, $width, $height, $image);
            }
        } else {
            $this->placeholderBox($y, $width, $height, $image);
        }
        $y += $height + 14;
        $caption = 'Fig. ' . $chapterNum . '.' . $image['slot'] . ' — ' . ($image['caption'] ?: 'Visuel');
        $this->pdf->text($this->marginLeft(), $y, 'Times-Italic', 9.5, $this->truncate($caption, 'Times-Italic', 9.5, $width));
        return $y + 18;
    }

    private function placeholderBox(float $y, float $width, float $height, array $image): void
    {
        $this->pdf->rect($this->marginLeft(), $y, $width, $height, 0.93);
        $label = 'Emplacement visuel — ' . ($image['spec'] ?: '300 dpi');
        $x = $this->marginLeft() + ($width - $this->pdf->width($label, 'Helvetica', 9)) / 2;
        $this->pdf->text($x, $y + $height / 2, 'Helvetica', 9, $label);
    }

    // ── Aides de composition ───────────────────────────────────────────────

    /** $kind : 'normal' (titre courant + folio), 'opener' (folio posé plus tard), 'blank' (rien). */
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
        $text = mb_strtoupper($isRecto ? ($this->runningRecto ?: $this->book['title']) : $this->book['title']);
        $text = $this->truncate($text, 'Helvetica', 7.5, $this->textWidth() * 0.8);
        $x = $isRecto
            ? $this->w - $this->marginRight() - $this->pdf->width($text, 'Helvetica', 7.5, 1.6)
            : $this->marginLeft();
        $this->pdf->text($x, $this->top - 14, 'Helvetica', 7.5, $text, 0, 1.6);
    }

    private function drawFolio(): void
    {
        $folio = (string) $this->pageNum;
        $isRecto = $this->pageNum % 2 === 1;
        $x = $isRecto
            ? $this->w - $this->marginRight() - $this->pdf->width($folio, 'Times-Roman', 9.5)
            : $this->marginLeft();
        $this->pdf->text($x, $this->h - $this->bottom + 22, 'Times-Roman', 9.5, $folio);
    }

    private function ensureRoom(float $y, float $needed): float
    {
        if ($y + $needed > $this->h - $this->bottom) {
            $this->newPage();
            return $this->top + 16;
        }
        return $y;
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
                'tw'   => max(0.0, min(6.0, $tw)),
            ];
        }
        return $out;
    }

    private function wrap(string $text, string $font, float $size, float $maxWidth): array
    {
        return array_map(fn ($l) => $l['text'], $this->justify($text, $font, $size, $maxWidth, 0, 0));
    }

    private function wrapCentered(string $text, string $font, float $size, float $maxWidth): array
    {
        return $this->wrap($text, $font, $size, $maxWidth);
    }

    private function truncate(string $text, string $font, float $size, float $maxWidth): string
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

/** Writer PDF bas niveau : polices de base, WinAnsi, JPEG, Flate. */
final class MiniPdf
{
    private array $metrics;
    private array $fontIds = [];
    private array $pages = [];
    private string $current = '';
    private bool $open = false;
    private array $images = [];   // name => [data, w, h, colorspace]

    public function __construct(private float $w, private float $h)
    {
        $this->metrics = require APP_ROOT . '/app/Core/corefonts.php';
        $i = 1;
        foreach (array_keys($this->metrics) as $font) {
            $this->fontIds[$font] = 'F' . $i++;
        }
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

    /** y mesuré depuis le HAUT de la page (converti en interne). */
    public function text(float $x, float $y, string $font, float $size, string $str, float $wordSpacing = 0, float $charSpacing = 0): void
    {
        $id = $this->fontIds[$font] ?? 'F1';
        $encoded = $this->escape($this->toWinAnsi($str));
        $yPdf = $this->h - $y;
        $this->current .= sprintf(
            "BT /%s %.2F Tf %.3F Tw %.3F Tc %.2F %.2F Td (%s) Tj ET\n",
            $id, $size, $wordSpacing, $charSpacing, $x, $yPdf, $encoded
        );
    }

    public function rect(float $x, float $y, float $w, float $h, float $gray): void
    {
        $this->current .= sprintf("%.3F g %.2F %.2F %.2F %.2F re f 0 g\n", $gray, $x, $this->h - $y - $h, $w, $h);
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

        // Polices
        $fontRefs = [];
        foreach ($this->fontIds as $name => $id) {
            $num = $add("<< /Type /Font /Subtype /Type1 /BaseFont /$name /Encoding /WinAnsiEncoding >>");
            $fontRefs[$id] = $num;
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
