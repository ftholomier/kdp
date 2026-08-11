<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Vignettes des thèmes de mise en page intérieure (sélecteur de l'étape 07).
 * Chaque vignette est une page d'ouverture de chapitre simulée en GD,
 * fidèle au style du thème et teintée de l'accent de la couverture.
 */
final class InteriorThemes
{
    public static function thumb(string $slug, string $accentHex): string
    {
        $w = 300;
        $h = 450;
        $im = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($im, 255, 253, 247);
        imagefill($im, 0, 0, $white);

        [$ar, $ag, $ab] = self::rgb($accentHex);
        $accent = imagecolorallocate($im, $ar, $ag, $ab);
        $accentSoft = imagecolorallocate($im, (int) ($ar + (255 - $ar) * 0.85), (int) ($ag + (255 - $ag) * 0.85), (int) ($ab + (255 - $ab) * 0.85));
        $ink = imagecolorallocate($im, 30, 28, 24);
        $gray = imagecolorallocate($im, 150, 143, 128);
        $lightGray = imagecolorallocate($im, 216, 210, 196);

        $fonts = APP_ROOT . '/app/fonts/';
        $serif = $fonts . 'InstrumentSerif-Regular.ttf';
        $mono = $fonts . 'IBMPlexMono-Medium.ttf';
        $sans = $fonts . 'Poppins-SemiBold.ttf';
        $bebas = $fonts . 'BebasNeue.ttf';
        $dm = $fonts . 'DMSerifDisplay.ttf';

        // Fausses lignes de texte courant
        $bodyLines = function (int $y, int $count) use ($im, $w, $lightGray): void {
            for ($i = 0; $i < $count; $i++) {
                $len = $i === $count - 1 ? 0.55 : (0.86 + ($i % 3) * 0.03);
                imagefilledrectangle($im, 34, $y + $i * 13, (int) (34 + ($w - 68) * $len), $y + $i * 13 + 4, $lightGray);
            }
        };

        switch ($slug) {
            case 'moderne':
                imagettftext($im, 44, 0, $w - 92, 92, $accentSoft === 0 ? $accent : imagecolorallocate($im, (int) ($ar + (255 - $ar) * 0.45), (int) ($ag + (255 - $ag) * 0.45), (int) ($ab + (255 - $ab) * 0.45)), $sans, '03');
                imagettftext($im, 9, 0, 34, 66, $gray, $mono, 'C H A P I T R E  T R O I S');
                imagettftext($im, 19, 0, 34, 108, $ink, $sans, 'Un titre de');
                imagettftext($im, 19, 0, 34, 136, $ink, $sans, 'chapitre net');
                imagefilledrectangle($im, 34, 152, 78, 156, $accent);
                $bodyLines(190, 10);
                imagefilledrectangle($im, 34, 330, $w - 34, 396, $accentSoft);
                imagefilledrectangle($im, 34, 330, 39, 396, $accent);
                imagettftext($im, 8, 0, 50, 350, $accent, $mono, 'CONSEIL');
                break;

            case 'magazine':
                imagefilledrectangle($im, 0, 0, $w, 128, $accent);
                $whiteText = imagecolorallocate($im, 255, 255, 255);
                imagettftext($im, 9, 0, 34, 44, $whiteText, $mono, 'C H A P I T R E  3');
                imagettftext($im, 30, 0, 34, 92, $whiteText, $bebas, 'UN TITRE FORT');
                $bodyLines(170, 10);
                imagefilledrectangle($im, 34, 320, $w - 34, 386, $accentSoft);
                imagefilledrectangle($im, 34, 320, 39, 386, $accent);
                imagettftext($im, 8, 0, 50, 340, $accent, $mono, 'CHIFFRE CLE');
                break;

            case 'premium':
                // Aplat magazine + 2 colonnes + encart réversé + décalage
                $whiteTxt = imagecolorallocate($im, 255, 255, 255);
                imagefilledrectangle($im, 0, 0, $w, 150, $accent);
                imagettftext($im, 8, 0, 30, 34, $whiteTxt, $mono, 'C H A P I T R E  3');
                imagefilledrectangle($im, 30, 42, 52, 44, $whiteTxt);
                $tint = imagecolorallocate($im, (int) ($ar + (255 - $ar) * 0.45), (int) ($ag + (255 - $ag) * 0.45), (int) ($ab + (255 - $ab) * 0.45));
                imagettftext($im, 40, 0, $w - 88, 140, $tint, $sans, '03');
                imagettftext($im, 16, 0, 30, 80, $whiteTxt, $sans, 'Un titre');
                imagettftext($im, 16, 0, 30, 104, $whiteTxt, $sans, 'magazine');
                imagefilledrectangle($im, 0, 158, (int) ($w * 0.44), 164, $accentSoft);
                // Deux colonnes de texte courant
                $colW = (int) (($w - 68 - 14) / 2);
                for ($c = 0; $c < 2; $c++) {
                    $cx = 34 + $c * ($colW + 14);
                    for ($i = 0; $i < 9; $i++) {
                        $len = $i === 8 ? 0.55 : (0.84 + ($i % 3) * 0.05);
                        imagefilledrectangle($im, $cx, 186 + $i * 12, (int) ($cx + $colW * $len), 190 + $i * 12, $lightGray);
                    }
                }
                // Encart aplat réversé dans la colonne droite + pavé numéroté à gauche
                $ex = 34 + $colW + 14;
                imagefilledrectangle($im, $ex, 306, $ex + $colW, 380, $accent);
                imagettftext($im, 7, 0, $ex + 10, 324, $whiteTxt, $mono, 'A RETENIR');
                for ($i = 0; $i < 3; $i++) {
                    imagefilledrectangle($im, $ex + 10, 336 + $i * 12, $ex + $colW - 12, 340 + $i * 12, $tint);
                }
                imagefilledrectangle($im, 34, 306, 50, 322, $accent);
                imagettftext($im, 7, 0, 38, 318, $whiteTxt, $mono, '02');
                imagefilledrectangle($im, 56, 310, 34 + $colW, 314, $gray);
                for ($i = 0; $i < 5; $i++) {
                    imagefilledrectangle($im, 34, 336 + $i * 12, (int) (34 + $colW * ($i === 4 ? 0.5 : 0.9)), 340 + $i * 12, $lightGray);
                }
                break;

            case 'elegant':
                imagettftext($im, 8, 0, (int) ($w / 2 - 56), 60, $gray, $mono, 'C H A P I T R E  I I I');
                imagefilledrectangle($im, (int) ($w / 2 - 34), 74, (int) ($w / 2 + 34), 75, $ink);
                imagettftext($im, 21, 0, 52, 120, $ink, $dm, 'Un titre serein');
                imagefilledrectangle($im, (int) ($w / 2 - 34), 140, (int) ($w / 2 + 34), 141, $ink);
                $bodyLines(180, 10);
                imagefilledrectangle($im, 34, 330, $w - 34, 331, $ink);
                imagettftext($im, 8, 0, 40, 352, $gray, $mono, 'A RETENIR');
                imagefilledrectangle($im, 34, 386, $w - 34, 387, $ink);
                break;

            default: // editorial
                imagettftext($im, 9, 0, 34, 62, $accent, $mono, 'C H A P I T R E  T R O I S');
                imagefilledrectangle($im, 34, 74, 66, 77, $accent);
                imagettftext($im, 22, 0, 34, 118, $ink, $serif, 'Un titre de');
                imagettftext($im, 22, 0, 34, 148, $ink, $serif, 'chapitre élégant');
                $bodyLines(190, 10);
                imagefilledrectangle($im, 34, 330, $w - 34, 396, $accentSoft);
                imagefilledrectangle($im, 34, 330, 39, 396, $accent);
                imagettftext($im, 8, 0, 50, 350, $accent, $mono, 'A RETENIR');
        }

        // Folio
        imagettftext($im, 8, 0, $w - 48, $h - 22, $gray, $mono, '43');

        ob_start();
        imagepng($im, null, 6);
        $png = (string) ob_get_clean();
        imagedestroy($im);
        return 'data:image/png;base64,' . base64_encode($png);
    }

    private static function rgb(string $hex): array
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
}
