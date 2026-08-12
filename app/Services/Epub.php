<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Db;
use App\Core\Util;

/**
 * Export EPUB 3 pour le Kindle (KDP eBook) — 100 % PHP natif (ZipArchive).
 *
 * Contenu complet : couverture, page de titre, sommaire navigable, chapitres
 * avec sous-titres, listes, ENCADRÉS stylés et photos incorporées. Mise en
 * forme sobre et liquide (le lecteur Kindle reste maître des polices).
 */
final class Epub
{
    public static function build(array $project, array $book): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('Extension PHP zip absente sur cet hébergement — export EPUB indisponible.');
        }
        $projectId = (int) $project['id'];
        $dir = (string) Config::get('paths.exports');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $file = $dir . '/projet-' . $projectId . '.epub';
        @unlink($file);

        $zip = new \ZipArchive();
        if ($zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Impossible de créer le fichier EPUB.');
        }
        // Le fichier « mimetype » doit être PREMIER et non compressé (spec EPUB)
        $zip->addFromString('mimetype', 'application/epub+zip');
        $zip->setCompressionName('mimetype', \ZipArchive::CM_STORE);

        $zip->addFromString('META-INF/container.xml',
            '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">'
            . '<rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles>'
            . '</container>');

        $e = fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $manifest = [];
        $spine = [];

        // ── Couverture (rendu réel du studio) ──
        $coverRow = Db::one('SELECT * FROM covers WHERE project_id = ?', [$projectId]);
        $hasCover = false;
        if ($coverRow) {
            try {
                $cover = Covers::get($project, null, ['display_name' => $book['author']]);
                $illus = CoverStudio::illusPath($projectId);
                $jpeg = CoverStudio::jpegFromElements(
                    Covers::frontElements($cover, is_file($illus)),
                    is_file($illus) ? $illus : null
                );
                $zip->addFromString('OEBPS/img/cover.jpg', $jpeg);
                $manifest[] = '<item id="cover-img" href="img/cover.jpg" media-type="image/jpeg" properties="cover-image"/>';
                $zip->addFromString('OEBPS/cover.xhtml', self::page('Couverture',
                    '<div class="cover"><img src="img/cover.jpg" alt="' . $e($book['title']) . '"/></div>'));
                $manifest[] = '<item id="cover" href="cover.xhtml" media-type="application/xhtml+xml"/>';
                $spine[] = '<itemref idref="cover"/>';
                $hasCover = true;
            } catch (\Throwable $eIgnored) {
                // couverture indisponible : l'EPUB reste valide sans elle
            }
        }

        // ── Page de titre ──
        $zip->addFromString('OEBPS/title.xhtml', self::page($book['title'],
            '<div class="titlepage"><p class="tp-kicker">' . $e(mb_strtoupper($book['author'])) . '</p>'
            . '<h1>' . $e($book['title']) . '</h1>'
            . ($book['subtitle'] !== '' ? '<p class="tp-sub">' . $e($book['subtitle']) . '</p>' : '')
            . '<p class="tp-year">© ' . $e((string) $book['year']) . '</p></div>'));
        $manifest[] = '<item id="title" href="title.xhtml" media-type="application/xhtml+xml"/>';
        $spine[] = '<itemref idref="title"/>';

        // ── Chapitres ──
        $uploads = (string) Config::get('paths.uploads');
        $navEntries = [];
        $labels = Util::CALLOUTS;
        foreach ($book['chapters'] as $ci => $chapter) {
            $id = 'chap' . ($ci + 1);
            $html = '<h1 class="chap">'
                . '<span class="kicker">' . $e(mb_strtoupper((string) ($chapter['label'] ?? 'Chapitre'))) . '</span>'
                . $e($chapter['title']) . '</h1>';

            $imagesDone = false;
            foreach ($chapter['sections'] as $si => $section) {
                if ($si > 0) {
                    $html .= '<h2>' . $e($section['title']) . '</h2>';
                }
                $blocks = !empty($section['blocks'])
                    ? $section['blocks']
                    : array_map(fn ($p) => ['t' => 'p', 'text' => $p], $section['paragraphs'] ?? []);
                foreach ($blocks as $block) {
                    $type = $block['t'] ?? 'p';
                    if ($type === 'call') {
                        $kind = (string) ($block['kind'] ?? 'retenir');
                        $html .= '<aside class="callout k-' . $e($kind) . '"><p class="co-label">'
                            . $e(mb_strtoupper($labels[$kind] ?? $kind)) . '</p>';
                        foreach (preg_split('/\n\s*\n/', (string) $block['text']) ?: [] as $paragraph) {
                            $html .= '<p>' . $e(trim($paragraph)) . '</p>';
                        }
                        $html .= '</aside>';
                    } elseif ($type === 'h') {
                        $html .= '<h3>' . $e((string) $block['text']) . '</h3>';
                    } elseif ($type === 'list') {
                        $html .= '<ul>' . implode('', array_map(fn ($i) => '<li>' . $e((string) $i) . '</li>', (array) $block['items'])) . '</ul>';
                    } else {
                        $html .= '<p>' . $e((string) $block['text']) . '</p>';
                    }
                }
                if (!$imagesDone && !empty($chapter['images'])) {
                    $imagesDone = true;
                    foreach ($chapter['images'] as $image) {
                        if (empty($image['filename']) || !is_file($uploads . '/' . $image['filename'])) {
                            continue;
                        }
                        $imgName = 'img/fig-' . $chapter['num'] . '-' . $image['slot'] . '.jpg';
                        $zip->addFile($uploads . '/' . $image['filename'], 'OEBPS/' . $imgName);
                        $manifest[] = '<item id="fig' . $chapter['num'] . 'x' . $image['slot'] . '" href="' . $imgName . '" media-type="image/jpeg"/>';
                        $html .= '<figure><img src="' . $imgName . '" alt="' . $e((string) ($image['caption'] ?? '')) . '"/>'
                            . '<figcaption>Fig. ' . $chapter['num'] . '.' . $image['slot'] . ' — ' . $e((string) ($image['caption'] ?: 'Visuel')) . '</figcaption></figure>';
                    }
                }
            }
            $zip->addFromString('OEBPS/' . $id . '.xhtml', self::page($chapter['title'], $html));
            $manifest[] = '<item id="' . $id . '" href="' . $id . '.xhtml" media-type="application/xhtml+xml"/>';
            $spine[] = '<itemref idref="' . $id . '"/>';
            $navEntries[] = '<li><a href="' . $id . '.xhtml">' . $e($chapter['title']) . '</a></li>';
        }

        // ── Sommaire navigable ──
        $zip->addFromString('OEBPS/nav.xhtml', self::page('Sommaire',
            '<nav epub:type="toc" id="toc"><h1>Sommaire</h1><ol>' . implode('', $navEntries) . '</ol></nav>', true));
        $manifest[] = '<item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>';
        array_splice($spine, $hasCover ? 2 : 1, 0, ['<itemref idref="nav"/>']);

        // ── Feuille de style ──
        $zip->addFromString('OEBPS/style.css', self::css());
        $manifest[] = '<item id="css" href="style.css" media-type="text/css"/>';

        // ── content.opf ──
        $uuid = 'urn:uuid:' . substr(md5('tirage-' . $projectId), 0, 8) . '-' . substr(md5('tirage-' . $projectId), 8, 4)
            . '-' . substr(md5('tirage-' . $projectId), 12, 4) . '-' . substr(md5('tirage-' . $projectId), 16, 4)
            . '-' . substr(md5('tirage-' . $projectId), 20, 12);
        $zip->addFromString('OEBPS/content.opf',
            '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="uid" xml:lang="fr">'
            . '<metadata xmlns:dc="http://purl.org/dc/elements/1.1/">'
            . '<dc:identifier id="uid">' . $uuid . '</dc:identifier>'
            . '<dc:title>' . $e($book['title']) . '</dc:title>'
            . '<dc:creator>' . $e($book['author']) . '</dc:creator>'
            . '<dc:language>fr</dc:language>'
            . '<dc:date>' . date('Y-m-d') . '</dc:date>'
            . '<meta property="dcterms:modified">' . gmdate('Y-m-d\TH:i:s\Z') . '</meta>'
            . ($hasCover ? '<meta name="cover" content="cover-img"/>' : '')
            . '</metadata>'
            . '<manifest>' . implode('', $manifest) . '</manifest>'
            . '<spine>' . implode('', $spine) . '</spine>'
            . '</package>');

        $zip->close();
        return $file;
    }

    private static function page(string $title, string $body, bool $isNav = false): string
    {
        $e = fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<html xmlns="http://www.w3.org/1999/xhtml"' . ($isNav ? ' xmlns:epub="http://www.idpf.org/2007/ops"' : '') . ' xml:lang="fr">'
            . '<head><title>' . $e($title) . '</title><link rel="stylesheet" type="text/css" href="style.css"/></head>'
            . '<body>' . $body . '</body></html>';
    }

    private static function css(): string
    {
        return <<<'CSS'
body { font-family: serif; line-height: 1.55; margin: 0 4%; }
p { margin: 0 0 0.65em; text-align: justify; }
h1.chap { font-size: 1.7em; line-height: 1.15; margin: 1.4em 0 1em; }
h1.chap .kicker { display: block; font-size: 0.5em; letter-spacing: 0.14em; color: #8a5a2a; margin-bottom: 0.6em; }
h2 { font-size: 1.25em; margin: 1.4em 0 0.6em; }
h3 { font-size: 1.05em; margin: 1.2em 0 0.5em; }
ul { margin: 0.4em 0 0.9em 1.1em; padding: 0; }
li { margin-bottom: 0.35em; }
aside.callout { border: 1px solid #d8d2c4; border-left: 4px solid #8a5a2a; background: #f7f3e9;
  padding: 0.7em 0.9em; margin: 1em 0; page-break-inside: avoid; }
aside.callout .co-label { font-size: 0.72em; letter-spacing: 0.16em; color: #8a5a2a; margin-bottom: 0.4em; }
figure { margin: 1.2em 0; text-align: center; page-break-inside: avoid; }
figure img { max-width: 100%; }
figcaption { font-size: 0.82em; font-style: italic; color: #6e685c; margin-top: 0.4em; }
.cover { text-align: center; margin: 0; }
.cover img { max-width: 100%; max-height: 98vh; }
.titlepage { text-align: center; margin-top: 18%; }
.titlepage h1 { font-size: 2em; line-height: 1.15; }
.tp-kicker { letter-spacing: 0.2em; font-size: 0.8em; color: #6e685c; }
.tp-sub { font-style: italic; color: #6e685c; margin-top: 1em; }
.tp-year { margin-top: 3em; font-size: 0.85em; color: #6e685c; }
nav#toc ol { list-style: none; margin: 1em 0; padding: 0; }
nav#toc li { margin-bottom: 0.6em; }
nav#toc a { text-decoration: none; }
CSS;
    }
}
