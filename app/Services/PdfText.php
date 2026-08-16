<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Extraction de texte d'un PDF — 100 % PHP natif, sans binaire externe
 * (pdftotext n'existe pas sur un hébergement mutualisé).
 *
 * Prend en charge : objets classiques et flux d'objets compressés (ObjStm),
 * flux FlateDecode, polices simples (WinAnsi/MacRoman) et polices composites
 * Identity-H via leur table /ToUnicode. Restitue des LIGNES avec leur corps
 * de police et leur position — matière première de la détection de structure
 * (titres de chapitre, sections, paragraphes).
 */
final class PdfText
{
    /** @return array{pages:array<int,array<int,array{text:string,size:float,x:float,y:float}>>,chars:int} */
    public static function extract(string $file): array
    {
        $raw = (string) file_get_contents($file);
        if (!str_starts_with($raw, '%PDF')) {
            throw new \RuntimeException('Ce fichier n\'est pas un PDF valide.');
        }
        if (str_contains($raw, '/Encrypt')) {
            throw new \RuntimeException('PDF protégé (chiffré) : retirez la protection avant l\'import.');
        }

        $objects = self::parseObjects($raw);
        $pages = [];
        foreach ($objects as $num => $obj) {
            if (!preg_match('~/Type\s*/Page[^s]~', $obj['dict'])) {
                continue;
            }
            $content = self::pageContent($obj, $objects);
            if ($content === '') {
                continue;
            }
            $fonts = self::pageFonts($obj, $objects);
            $lines = self::readContent($content, $fonts);
            if ($lines) {
                $pages[] = $lines;
            }
        }
        if (!$pages) {
            throw new \RuntimeException(
                'Aucun texte extractible : ce PDF est probablement un scan (images). '
                . 'Utilisez un PDF « texte » ou passez-le par un OCR au préalable.'
            );
        }
        $chars = 0;
        foreach ($pages as $lines) {
            foreach ($lines as $l) {
                $chars += mb_strlen($l['text']);
            }
        }
        return ['pages' => $pages, 'chars' => $chars];
    }

    // ── Objets PDF ─────────────────────────────────────────────────────────

    /** @return array<int,array{dict:string,stream:?string}> */
    private static function parseObjects(string $raw): array
    {
        $objects = [];
        $offset = 0;
        while (preg_match('/(\d+)\s+(\d+)\s+obj\b/', $raw, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $num = (int) $m[1][0];
            $start = $m[0][1] + strlen($m[0][0]);
            $end = strpos($raw, 'endobj', $start);
            if ($end === false) {
                break;
            }
            $body = substr($raw, $start, $end - $start);
            $offset = $end + 6;

            $stream = null;
            $dict = $body;
            $sPos = strpos($body, 'stream');
            if ($sPos !== false) {
                $dict = substr($body, 0, $sPos);
                $dataStart = $sPos + 6;
                // saut de ligne après « stream »
                if (substr($body, $dataStart, 2) === "\r\n") {
                    $dataStart += 2;
                } elseif (in_array(substr($body, $dataStart, 1), ["\n", "\r"], true)) {
                    $dataStart += 1;
                }
                $ePos = strpos($body, 'endstream', $dataStart);
                $data = substr($body, $dataStart, ($ePos === false ? strlen($body) : $ePos) - $dataStart);
                $stream = self::decodeStream($dict, $data);
            }
            $objects[$num] = ['dict' => $dict, 'stream' => $stream];
        }

        // Flux d'objets compressés (PDF 1.5+) : ils contiennent d'autres objets
        foreach ($objects as $obj) {
            if ($obj['stream'] === null || !preg_match('~/Type\s*/ObjStm~', $obj['dict'])) {
                continue;
            }
            preg_match('/\/N\s+(\d+)/', $obj['dict'], $mN);
            preg_match('/\/First\s+(\d+)/', $obj['dict'], $mF);
            $n = (int) ($mN[1] ?? 0);
            $first = (int) ($mF[1] ?? 0);
            if ($n <= 0 || $first <= 0) {
                continue;
            }
            $header = substr($obj['stream'], 0, $first);
            $nums = preg_split('/\s+/', trim($header)) ?: [];
            for ($i = 0; $i < $n; $i++) {
                $objNum = (int) ($nums[$i * 2] ?? 0);
                $rel = (int) ($nums[$i * 2 + 1] ?? 0);
                if ($objNum <= 0 || isset($objects[$objNum])) {
                    continue;
                }
                $nextRel = isset($nums[($i + 1) * 2 + 1]) ? (int) $nums[($i + 1) * 2 + 1] : null;
                $len = $nextRel !== null ? $nextRel - $rel : null;
                $objects[$objNum] = [
                    'dict'   => $len !== null ? substr($obj['stream'], $first + $rel, $len) : substr($obj['stream'], $first + $rel),
                    'stream' => null,
                ];
            }
        }
        return $objects;
    }

    private static function decodeStream(string $dict, string $data): ?string
    {
        if (preg_match('~/Filter\s*/(\w+)~', $dict, $m)) {
            $filter = $m[1];
        } elseif (preg_match('~/Filter\s*\[\s*/(\w+)~', $dict, $m)) {
            $filter = $m[1];
        } else {
            return $data;
        }
        if ($filter === 'FlateDecode') {
            $out = @gzuncompress($data);
            if ($out === false) {
                $out = @gzinflate(substr($data, 2));       // en-tête zlib partiel
            }
            if ($out === false) {
                $out = @gzinflate($data);
            }
            return $out === false ? null : $out;
        }
        if ($filter === 'ASCIIHexDecode') {
            return (string) hex2bin(preg_replace('/[^0-9a-f]/i', '', explode('>', $data)[0]) ?? '');
        }
        // DCTDecode (image), LZW, etc. : pas de texte à en tirer
        return null;
    }

    /** Concatène le ou les flux de contenu d'une page. */
    private static function pageContent(array $page, array $objects): string
    {
        if (!preg_match('~/Contents\s*(\d+)\s+\d+\s+R|/Contents\s*\[([^\]]+)\]~', $page['dict'], $m)) {
            return '';
        }
        $refs = [];
        if (!empty($m[1])) {
            $refs[] = (int) $m[1];
        } elseif (!empty($m[2])) {
            preg_match_all('/(\d+)\s+\d+\s+R/', $m[2], $mm);
            foreach ($mm[1] as $r) {
                $refs[] = (int) $r;
            }
        }
        $out = '';
        foreach ($refs as $ref) {
            $out .= ($objects[$ref]['stream'] ?? '') . "\n";
        }
        return $out;
    }

    /**
     * Tables de décodage des polices de la page : nom interne → table de
     * correspondance code → caractère (ToUnicode), ou null pour du WinAnsi.
     * @return array<string,?array<int,string>>
     */
    private static function pageFonts(array $page, array $objects): array
    {
        if (!preg_match('~/Font\s*<<(.+?)>>~s', $page['dict'], $m)) {
            // /Resources par référence
            if (preg_match('~/Resources\s+(\d+)\s+\d+\s+R~', $page['dict'], $mr)
                && isset($objects[(int) $mr[1]])
                && preg_match('~/Font\s*<<(.+?)>>~s', $objects[(int) $mr[1]]['dict'], $m2)) {
                $m = $m2;
            } else {
                return [];
            }
        }
        $fonts = [];
        preg_match_all('~/([A-Za-z0-9_.-]+)\s+(\d+)\s+\d+\s+R~', $m[1], $mm, PREG_SET_ORDER);
        foreach ($mm as $set) {
            $name = $set[1];
            $fontObj = $objects[(int) $set[2]] ?? null;
            if (!$fontObj) {
                $fonts[$name] = null;
                continue;
            }
            $map = null;
            if (preg_match('~/ToUnicode\s+(\d+)\s+\d+\s+R~', $fontObj['dict'], $mt)) {
                $cmap = $objects[(int) $mt[1]]['stream'] ?? null;
                if ($cmap !== null) {
                    $map = self::parseCMap($cmap);
                }
            }
            $fonts[$name] = $map;
        }
        return $fonts;
    }

    /** @return array<int,string> code → caractère UTF-8 */
    private static function parseCMap(string $cmap): array
    {
        $map = [];
        // <src> <dst>
        if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $blocks)) {
            foreach ($blocks[1] as $block) {
                preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $block, $pairs, PREG_SET_ORDER);
                foreach ($pairs as $p) {
                    $map[hexdec($p[1])] = self::utf16beToUtf8($p[2]);
                }
            }
        }
        // <lo> <hi> <dstStart>  |  <lo> <hi> [<d1> <d2> …]
        if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmap, $blocks)) {
            foreach ($blocks[1] as $block) {
                preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(<([0-9A-Fa-f]+)>|\[(.*?)\])/s', $block, $ranges, PREG_SET_ORDER);
                foreach ($ranges as $r) {
                    $lo = (int) hexdec($r[1]);
                    $hi = (int) hexdec($r[2]);
                    if ($hi - $lo > 65535) {
                        continue;
                    }
                    if (!empty($r[4])) {
                        $base = (int) hexdec($r[4]);
                        for ($c = $lo; $c <= $hi; $c++) {
                            $map[$c] = self::codepointToUtf8($base + ($c - $lo));
                        }
                    } elseif (isset($r[5])) {
                        preg_match_all('/<([0-9A-Fa-f]+)>/', $r[5], $items);
                        foreach ($items[1] as $i => $hex) {
                            $map[$lo + $i] = self::utf16beToUtf8($hex);
                        }
                    }
                }
            }
        }
        return $map;
    }

    private static function utf16beToUtf8(string $hex): string
    {
        $bin = (string) hex2bin(strlen($hex) % 2 ? '0' . $hex : $hex);
        $out = @iconv('UTF-16BE', 'UTF-8//IGNORE', $bin);
        return $out === false ? '' : $out;
    }

    private static function codepointToUtf8(int $cp): string
    {
        return $cp > 0 && $cp < 0x110000 ? (string) mb_chr($cp, 'UTF-8') : '';
    }

    // ── Lecture d'un flux de contenu ───────────────────────────────────────

    /** @return array<int,array{text:string,size:float,x:float,y:float}> */
    private static function readContent(string $content, array $fonts): array
    {
        $lines = [];
        $current = '';
        $curSize = 10.0;
        $curFont = null;
        $x = 0.0;
        $y = 0.0;
        $lastY = null;
        $leading = 0.0;
        $scale = 1.0;   // facteur d'échelle de la matrice texte

        $flush = function () use (&$current, &$lines, &$curSize, &$x, &$y): void {
            $text = trim(preg_replace('/\s+/u', ' ', $current) ?? '');
            if ($text !== '') {
                $lines[] = ['text' => $text, 'size' => round($curSize, 1), 'x' => $x, 'y' => $y];
            }
            $current = '';
        };

        $tokens = self::tokenize($content);
        $stack = [];
        foreach ($tokens as $tok) {
            [$type, $value] = $tok;
            if ($type !== 'op') {
                $stack[] = $tok;
                continue;
            }
            switch ($value) {
                case 'Tf':
                    $size = (float) (self::popNum($stack) ?? 10);
                    $name = self::popName($stack);
                    $curSize = $size * $scale;
                    $curFont = $name;
                    break;
                case 'Tm':
                    $nums = self::popNums($stack, 6);
                    $scale = abs((float) ($nums[3] ?? 1)) ?: 1.0;
                    $newY = (float) ($nums[5] ?? 0);
                    $newX = (float) ($nums[4] ?? 0);
                    if ($lastY !== null && abs($newY - $lastY) > 0.6) {
                        $flush();
                    }
                    $x = $newX;
                    $y = $newY;
                    $lastY = $newY;
                    break;
                case 'Td':
                case 'TD':
                    $nums = self::popNums($stack, 2);
                    $dy = (float) ($nums[1] ?? 0);
                    if ($value === 'TD') {
                        $leading = -$dy;
                    }
                    if (abs($dy) > 0.6) {
                        $flush();
                    }
                    $x += (float) ($nums[0] ?? 0);
                    $y += $dy;
                    $lastY = $y;
                    break;
                case 'TL':
                    $leading = (float) (self::popNum($stack) ?? 0);
                    break;
                case 'T*':
                    $flush();
                    $y -= $leading;
                    $lastY = $y;
                    break;
                case 'ET':
                    $flush();
                    break;
                case 'Tj':
                case "'":
                case '"':
                    if ($value !== 'Tj') {
                        $flush();
                    }
                    $str = self::popString($stack);
                    if ($str !== null) {
                        $current .= self::decodeString($str, $fonts[$curFont] ?? null);
                    }
                    break;
                case 'TJ':
                    $arr = array_pop($stack);
                    if (is_array($arr) && $arr[0] === 'array') {
                        foreach ($arr[1] as $item) {
                            if ($item[0] === 'str') {
                                $current .= self::decodeString($item[1], $fonts[$curFont] ?? null);
                            } elseif ($item[0] === 'num' && (float) $item[1] < -120) {
                                $current .= ' ';   // grand décalage = espace
                            }
                        }
                    }
                    break;
                default:
                    $stack = [];
            }
            if (!in_array($value, ['Tj', 'TJ', "'", '"'], true)) {
                $stack = [];
            }
        }
        $flush();
        return $lines;
    }

    /** @return array<int,array{0:string,1:mixed}> */
    private static function tokenize(string $s): array
    {
        $tokens = [];
        $len = strlen($s);
        $i = 0;
        while ($i < $len) {
            $c = $s[$i];
            if ($c === '%') {                                  // commentaire
                while ($i < $len && $s[$i] !== "\n") {
                    $i++;
                }
                continue;
            }
            if (ctype_space($c)) {
                $i++;
                continue;
            }
            if ($c === '(') {
                [$str, $i] = self::readLiteral($s, $i + 1);
                $tokens[] = ['str', $str];
                continue;
            }
            if ($c === '<' && ($s[$i + 1] ?? '') !== '<') {
                $end = strpos($s, '>', $i);
                $hex = substr($s, $i + 1, ($end === false ? $len : $end) - $i - 1);
                $tokens[] = ['hex', preg_replace('/[^0-9A-Fa-f]/', '', $hex) ?? ''];
                $i = ($end === false ? $len : $end + 1);
                continue;
            }
            if ($c === '<' && ($s[$i + 1] ?? '') === '<') {     // dictionnaire : ignoré
                $depth = 0;
                while ($i < $len) {
                    if ($s[$i] === '<' && ($s[$i + 1] ?? '') === '<') {
                        $depth++;
                        $i += 2;
                        continue;
                    }
                    if ($s[$i] === '>' && ($s[$i + 1] ?? '') === '>') {
                        $depth--;
                        $i += 2;
                        if ($depth <= 0) {
                            break;
                        }
                        continue;
                    }
                    $i++;
                }
                continue;
            }
            if ($c === '[') {
                $items = [];
                $i++;
                while ($i < $len && $s[$i] !== ']') {
                    if (ctype_space($s[$i])) {
                        $i++;
                        continue;
                    }
                    if ($s[$i] === '(') {
                        [$str, $i] = self::readLiteral($s, $i + 1);
                        $items[] = ['str', $str];
                        continue;
                    }
                    if ($s[$i] === '<') {
                        $end = strpos($s, '>', $i);
                        $items[] = ['hex', preg_replace('/[^0-9A-Fa-f]/', '', substr($s, $i + 1, ($end === false ? $len : $end) - $i - 1)) ?? ''];
                        $i = ($end === false ? $len : $end + 1);
                        continue;
                    }
                    $j = $i;
                    while ($j < $len && !ctype_space($s[$j]) && !in_array($s[$j], ['(', '<', ']'], true)) {
                        $j++;
                    }
                    $items[] = ['num', substr($s, $i, $j - $i)];
                    $i = $j;
                }
                $i++;
                $tokens[] = ['array', $items];
                continue;
            }
            if ($c === '/') {
                $j = $i + 1;
                while ($j < $len && !ctype_space($s[$j]) && !in_array($s[$j], ['/', '[', '(', '<', ']'], true)) {
                    $j++;
                }
                $tokens[] = ['name', substr($s, $i + 1, $j - $i - 1)];
                $i = $j;
                continue;
            }
            $j = $i;
            while ($j < $len && !ctype_space($s[$j]) && !in_array($s[$j], ['/', '[', '(', '<'], true)) {
                $j++;
            }
            $word = substr($s, $i, $j - $i);
            $i = $j;
            if ($word === '') {
                $i++;
                continue;
            }
            $tokens[] = is_numeric($word) ? ['num', $word] : ['op', $word];
        }
        return $tokens;
    }

    /** @return array{0:string,1:int} */
    private static function readLiteral(string $s, int $i): array
    {
        $out = '';
        $depth = 1;
        $len = strlen($s);
        while ($i < $len) {
            $c = $s[$i];
            if ($c === '\\') {
                $next = $s[$i + 1] ?? '';
                $map = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C", '(' => '(', ')' => ')', '\\' => '\\'];
                if (isset($map[$next])) {
                    $out .= $map[$next];
                    $i += 2;
                    continue;
                }
                if (ctype_digit($next)) {                   // \ddd octal
                    $oct = '';
                    $i++;
                    while (strlen($oct) < 3 && ctype_digit($s[$i] ?? '')) {
                        $oct .= $s[$i++];
                    }
                    $out .= chr(octdec($oct) & 0xFF);
                    continue;
                }
                $i += 2;
                continue;
            }
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
                if ($depth === 0) {
                    return [$out, $i + 1];
                }
            }
            $out .= $c;
            $i++;
        }
        return [$out, $i];
    }

    private static function decodeString(array $token, ?array $map): string
    {
        [$kind, $value] = $token;
        if ($kind === 'hex') {
            $hex = strlen($value) % 2 ? $value . '0' : $value;
            if ($map !== null) {
                $out = '';
                foreach (str_split($hex, 4) as $chunk) {      // Identity-H : 2 octets
                    $code = (int) hexdec(str_pad($chunk, 4, '0'));
                    $out .= $map[$code] ?? '';
                }
                return $out;
            }
            return self::winAnsi((string) hex2bin($hex));
        }
        if ($map !== null) {
            // Police composite : codes sur 2 octets
            $out = '';
            $len = strlen($value);
            for ($i = 0; $i + 1 < $len; $i += 2) {
                $code = (ord($value[$i]) << 8) | ord($value[$i + 1]);
                $out .= $map[$code] ?? '';
            }
            if (trim($out) !== '') {
                return $out;
            }
            // Certaines polices simples ont malgré tout un ToUnicode 1 octet
            $out = '';
            for ($i = 0; $i < $len; $i++) {
                $out .= $map[ord($value[$i])] ?? '';
            }
            return $out !== '' ? $out : self::winAnsi($value);
        }
        return self::winAnsi($value);
    }

    private static function winAnsi(string $bytes): string
    {
        $out = @iconv('Windows-1252', 'UTF-8//IGNORE', $bytes);
        return $out === false ? $bytes : $out;
    }

    // ── Petites aides de pile ──────────────────────────────────────────────

    private static function popNum(array &$stack): ?float
    {
        for ($i = count($stack) - 1; $i >= 0; $i--) {
            if ($stack[$i][0] === 'num') {
                $v = (float) $stack[$i][1];
                array_splice($stack, $i, 1);
                return $v;
            }
        }
        return null;
    }

    /** @return array<int,float> */
    private static function popNums(array &$stack, int $count): array
    {
        $nums = [];
        foreach ($stack as $tok) {
            if ($tok[0] === 'num') {
                $nums[] = (float) $tok[1];
            }
        }
        $stack = [];
        return array_slice($nums, -$count);
    }

    private static function popName(array &$stack): ?string
    {
        for ($i = count($stack) - 1; $i >= 0; $i--) {
            if ($stack[$i][0] === 'name') {
                return $stack[$i][1];
            }
        }
        return null;
    }

    /** @return array{0:string,1:string}|null */
    private static function popString(array &$stack): ?array
    {
        for ($i = count($stack) - 1; $i >= 0; $i--) {
            if (in_array($stack[$i][0], ['str', 'hex'], true)) {
                return $stack[$i];
            }
        }
        return null;
    }
}
