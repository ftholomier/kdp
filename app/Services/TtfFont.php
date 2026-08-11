<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Parseur TrueType minimal — le nécessaire pour INCORPORER une police dans
 * un PDF (Type0 / CIDFontType2 / Identity-H) : correspondance Unicode → glyphe
 * (cmap 4 et 12), chasses (hmtx), métriques (head/hhea), angle italique (post).
 * 100 % PHP natif.
 */
final class TtfFont
{
    /** @var array<string,self> */
    private static array $cache = [];

    public string $file;
    public string $postScriptName = 'Embedded';
    public int $unitsPerEm = 1000;
    public int $ascent = 800;
    public int $descent = -200;
    public int $capHeight = 700;
    public float $italicAngle = 0.0;
    /** @var int[] xMin yMin xMax yMax (unités de la police) */
    public array $bbox = [-100, -200, 1100, 900];
    public int $numGlyphs = 0;
    /** @var array<int,int> code Unicode => identifiant de glyphe */
    public array $cmap = [];
    /** @var array<int,int> identifiant de glyphe => chasse (unités) */
    public array $advances = [];

    public static function load(string $file): self
    {
        if (!isset(self::$cache[$file])) {
            $font = new self();
            $font->file = $file;
            $font->parse();
            self::$cache[$file] = $font;
        }
        return self::$cache[$file];
    }

    /** Largeur d'une chaîne UTF-8 en unités pour 1000/em. */
    public function widthMilli(string $text): float
    {
        $total = 0;
        foreach (self::codepoints($text) as $cp) {
            $gid = $this->cmap[$cp] ?? 0;
            $total += $this->advances[$gid] ?? ($this->advances[0] ?? $this->unitsPerEm / 2);
        }
        return $total * 1000 / $this->unitsPerEm;
    }

    /** Chaîne hexadécimale des identifiants de glyphes (Identity-H). */
    public function gidHex(string $text): string
    {
        $hex = '';
        foreach (self::codepoints($text) as $cp) {
            $hex .= sprintf('%04X', $this->cmap[$cp] ?? 0);
        }
        return $hex;
    }

    /** @return int[] points de code Unicode */
    public static function codepoints(string $text): array
    {
        $out = [];
        $len = strlen($text);
        $i = 0;
        while ($i < $len) {
            $byte = ord($text[$i]);
            if ($byte < 0x80) {
                $out[] = $byte;
                $i++;
            } elseif (($byte & 0xE0) === 0xC0) {
                $out[] = (($byte & 0x1F) << 6) | (ord($text[$i + 1] ?? "\0") & 0x3F);
                $i += 2;
            } elseif (($byte & 0xF0) === 0xE0) {
                $out[] = (($byte & 0x0F) << 12) | ((ord($text[$i + 1] ?? "\0") & 0x3F) << 6) | (ord($text[$i + 2] ?? "\0") & 0x3F);
                $i += 3;
            } elseif (($byte & 0xF8) === 0xF0) {
                $out[] = (($byte & 0x07) << 18) | ((ord($text[$i + 1] ?? "\0") & 0x3F) << 12)
                    | ((ord($text[$i + 2] ?? "\0") & 0x3F) << 6) | (ord($text[$i + 3] ?? "\0") & 0x3F);
                $i += 4;
            } else {
                $i++;
            }
        }
        return $out;
    }

    // ── Analyse binaire ────────────────────────────────────────────────────

    private function parse(): void
    {
        $data = (string) file_get_contents($this->file);
        if (strlen($data) < 12) {
            throw new \RuntimeException('Police illisible : ' . basename($this->file));
        }
        $u16 = fn (int $o): int => (ord($data[$o]) << 8) | ord($data[$o + 1]);
        $s16 = function (int $o) use ($u16): int {
            $v = $u16($o);
            return $v >= 0x8000 ? $v - 0x10000 : $v;
        };
        $u32 = fn (int $o): int => (ord($data[$o]) << 24) | (ord($data[$o + 1]) << 16) | (ord($data[$o + 2]) << 8) | ord($data[$o + 3]);

        $numTables = $u16(4);
        $tables = [];
        for ($i = 0; $i < $numTables; $i++) {
            $rec = 12 + $i * 16;
            $tables[substr($data, $rec, 4)] = ['off' => $u32($rec + 8), 'len' => $u32($rec + 12)];
        }
        foreach (['head', 'hhea', 'maxp', 'hmtx', 'cmap'] as $required) {
            if (!isset($tables[$required])) {
                throw new \RuntimeException('Table ' . $required . ' absente : ' . basename($this->file));
            }
        }

        // head — unitsPerEm + boîte englobante
        $head = $tables['head']['off'];
        $this->unitsPerEm = max(16, $u16($head + 18));
        $this->bbox = [$s16($head + 36), $s16($head + 38), $s16($head + 40), $s16($head + 42)];

        // hhea — métriques verticales + nombre de chasses
        $hhea = $tables['hhea']['off'];
        $this->ascent = $s16($hhea + 4);
        $this->descent = $s16($hhea + 6);
        $numberOfHMetrics = $u16($hhea + 34);

        // maxp — nombre de glyphes
        $this->numGlyphs = $u16($tables['maxp']['off'] + 4);

        // OS/2 — hauteur de capitale si disponible
        if (isset($tables['OS/2']) && $tables['OS/2']['len'] >= 90) {
            $os2 = $tables['OS/2']['off'];
            $version = $u16($os2);
            if ($version >= 2) {
                $this->capHeight = $s16($os2 + 88) ?: (int) ($this->ascent * 0.9);
            } else {
                $this->capHeight = (int) ($this->ascent * 0.9);
            }
        } else {
            $this->capHeight = (int) ($this->ascent * 0.9);
        }

        // post — angle italique
        if (isset($tables['post']) && $tables['post']['len'] >= 8) {
            $post = $tables['post']['off'];
            $this->italicAngle = $s16($post + 4) + $u16($post + 6) / 65536;
        }

        // hmtx — chasses
        $hmtx = $tables['hmtx']['off'];
        $advance = (int) ($this->unitsPerEm / 2);
        for ($gid = 0; $gid < $this->numGlyphs; $gid++) {
            if ($gid < $numberOfHMetrics) {
                $advance = $u16($hmtx + $gid * 4);
            }
            $this->advances[$gid] = $advance;
        }

        // cmap — sous-table Unicode (3,1) ou (0,x) ou (3,0)
        $cmapBase = $tables['cmap']['off'];
        $subCount = $u16($cmapBase + 2);
        $best = null;
        $bestScore = -1;
        for ($i = 0; $i < $subCount; $i++) {
            $rec = $cmapBase + 4 + $i * 8;
            $platform = $u16($rec);
            $encoding = $u16($rec + 2);
            $offset = $u32($rec + 4);
            $score = match (true) {
                $platform === 3 && $encoding === 10 => 5,
                $platform === 3 && $encoding === 1  => 4,
                $platform === 0                      => 3,
                $platform === 3 && $encoding === 0  => 1,
                default                              => 0,
            };
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $cmapBase + $offset;
            }
        }
        if ($best === null) {
            throw new \RuntimeException('Aucune table cmap Unicode : ' . basename($this->file));
        }

        $format = $u16($best);
        if ($format === 4) {
            $segCount = (int) ($u16($best + 6) / 2);
            $endBase = $best + 14;
            $startBase = $endBase + $segCount * 2 + 2;
            $deltaBase = $startBase + $segCount * 2;
            $rangeBase = $deltaBase + $segCount * 2;
            for ($seg = 0; $seg < $segCount; $seg++) {
                $end = $u16($endBase + $seg * 2);
                $start = $u16($startBase + $seg * 2);
                $delta = $u16($deltaBase + $seg * 2);
                $rangeOffset = $u16($rangeBase + $seg * 2);
                if ($start === 0xFFFF) {
                    continue;
                }
                for ($c = $start; $c <= $end && $c < 0x10000; $c++) {
                    if ($rangeOffset === 0) {
                        $gid = ($c + $delta) & 0xFFFF;
                    } else {
                        $glyphAddr = $rangeBase + $seg * 2 + $rangeOffset + ($c - $start) * 2;
                        $gid = $u16($glyphAddr);
                        if ($gid !== 0) {
                            $gid = ($gid + $delta) & 0xFFFF;
                        }
                    }
                    if ($gid !== 0) {
                        $this->cmap[$c] = $gid;
                    }
                }
            }
        } elseif ($format === 12) {
            $groups = $u32($best + 12);
            for ($g = 0; $g < $groups; $g++) {
                $rec = $best + 16 + $g * 12;
                $start = $u32($rec);
                $end = $u32($rec + 4);
                $gidStart = $u32($rec + 8);
                for ($c = $start; $c <= $end; $c++) {
                    $this->cmap[$c] = $gidStart + ($c - $start);
                }
            }
        } else {
            throw new \RuntimeException('Format cmap ' . $format . ' non géré : ' . basename($this->file));
        }

        // name — nom PostScript (identifiant 6)
        if (isset($tables['name'])) {
            $name = $tables['name']['off'];
            $count = $u16($name + 2);
            $stringBase = $name + $u16($name + 4);
            for ($i = 0; $i < $count; $i++) {
                $rec = $name + 6 + $i * 12;
                if ($u16($rec + 6) === 6) { // nameID 6 = PostScript name
                    $value = substr($data, $stringBase + $u16($rec + 10), $u16($rec + 8));
                    $clean = preg_replace('/[^A-Za-z0-9\-]/', '', str_replace("\0", '', $value)) ?? '';
                    if ($clean !== '') {
                        $this->postScriptName = $clean;
                        break;
                    }
                }
            }
        }
    }
}
