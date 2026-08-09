<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * Export .docx natif (ZipArchive + WordprocessingML) pour l'eBook Kindle.
 * KDP accepte le .docx directement pour la version numérique.
 */
final class Docx
{
    public static function build(array $project, array $book): string
    {
        $trim = Config::get('trims.' . $project['trim_format'], Config::get('trims.6x9'));
        $wTw = (int) round($trim['w_mm'] / 25.4 * 1440);
        $hTw = (int) round($trim['h_mm'] / 25.4 * 1440);
        $marginTw = (int) round(19 / 25.4 * 1440);

        $e = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $body = '';

        // Page de titre
        $body .= self::p($e($book['title']), ['size' => 56, 'bold' => true, 'align' => 'center', 'before' => 2400]);
        if ($book['subtitle'] !== '') {
            $body .= self::p($e($book['subtitle']), ['size' => 28, 'italic' => true, 'align' => 'center', 'before' => 240]);
        }
        $body .= self::p($e($book['author']), ['size' => 24, 'align' => 'center', 'before' => 720]);
        $body .= '<w:p><w:pPr><w:pageBreakBefore/></w:pPr></w:p>';

        // Copyright
        $body .= self::p($e('© ' . $book['year'] . ' ' . $book['author'] . '. Tous droits réservés.'), ['size' => 18]);
        $body .= self::p($e('Publié en autoédition via Amazon Kindle Direct Publishing.'), ['size' => 18]);

        foreach ($book['chapters'] as $chapter) {
            $body .= '<w:p><w:pPr><w:pageBreakBefore/></w:pPr></w:p>';
            $body .= self::p($e('Chapitre ' . $chapter['num']), ['size' => 20, 'caps' => true, 'color' => '888888']);
            $body .= self::p($e($chapter['title']), ['size' => 40, 'bold' => true, 'after' => 480, 'style' => 'Heading1']);
            foreach ($chapter['sections'] as $index => $section) {
                if ($index > 0) {
                    $body .= self::p($e($section['title']), ['size' => 26, 'bold' => true, 'before' => 360, 'after' => 200, 'style' => 'Heading2']);
                }
                foreach ($section['paragraphs'] as $paragraph) {
                    $body .= self::p($e($paragraph), ['size' => 24, 'justify' => true, 'after' => 200]);
                }
            }
        }

        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body>' . $body
            . '<w:sectPr><w:pgSz w:w="' . $wTw . '" w:h="' . $hTw . '"/>'
            . '<w:pgMar w:top="' . $marginTw . '" w:right="' . $marginTw . '" w:bottom="' . $marginTw . '" w:left="' . $marginTw . '"/>'
            . '</w:sectPr></w:body></w:document>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '</Types>';

        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>';

        $dir = (string) Config::get('paths.exports');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $file = $dir . '/projet-' . (int) $project['id'] . '-manuscrit.docx';
        @unlink($file);

        $zip = new \ZipArchive();
        if ($zip->open($file, \ZipArchive::CREATE) !== true) {
            throw new \RuntimeException('Impossible de créer le fichier .docx.');
        }
        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rels);
        $zip->addFromString('word/document.xml', $document);
        $zip->close();
        return $file;
    }

    private static function p(string $escapedText, array $opts): string
    {
        $pPr = '';
        if (!empty($opts['style'])) {
            $pPr .= '<w:pStyle w:val="' . $opts['style'] . '"/>';
        }
        if (!empty($opts['align'])) {
            $pPr .= '<w:jc w:val="' . $opts['align'] . '"/>';
        } elseif (!empty($opts['justify'])) {
            $pPr .= '<w:jc w:val="both"/>';
        }
        $before = (int) ($opts['before'] ?? 0);
        $after = (int) ($opts['after'] ?? 120);
        $pPr .= '<w:spacing w:before="' . $before . '" w:after="' . $after . '" w:line="340" w:lineRule="auto"/>';

        $rPr = '<w:rFonts w:ascii="Georgia" w:hAnsi="Georgia"/>';
        $rPr .= '<w:sz w:val="' . (int) ($opts['size'] ?? 24) . '"/>';
        if (!empty($opts['bold'])) {
            $rPr .= '<w:b/>';
        }
        if (!empty($opts['italic'])) {
            $rPr .= '<w:i/>';
        }
        if (!empty($opts['caps'])) {
            $rPr .= '<w:caps/>';
        }
        if (!empty($opts['color'])) {
            $rPr .= '<w:color w:val="' . $opts['color'] . '"/>';
        }

        return '<w:p><w:pPr>' . $pPr . '</w:pPr><w:r><w:rPr>' . $rPr . '</w:rPr>'
            . '<w:t xml:space="preserve">' . $escapedText . '</w:t></w:r></w:p>';
    }
}
