<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Support;

use Zxing\BinaryBitmap;
use Zxing\Common\HybridBinarizer;
use Zxing\Qrcode\QRCodeReader;

/**
 * Décode réellement le contenu d'un QR SVG produit par `App\Boutique\Billet\GenerateurImageQr` (mode
 * `compact` par défaut d'`Endroid\QrCode\Writer\SvgWriter` : un unique `<path>` concaténant des
 * rectangles `M{x},{y}L{x},{y}L{x},{y}L{x},{y}Z`, un par plage horizontale de modules noirs).
 *
 * Pas de dépendance à `ext-gd`/`ext-imagick` (absentes de l'image PHP de ce dépôt, cf. rapport du lot) :
 * les rectangles sont directement rastérisés en un tampon de luminance (`RasterLuminanceSource`), puis
 * décodés par le lecteur ZXing pur PHP `khanamiryan/qrcode-detector-decoder` (dev uniquement).
 */
final class QrSvgDecoder
{
    private const ECHELLE_PX_PAR_UNITE_SVG = 8;

    public static function decoder(string $svg): string
    {
        if (preg_match('/viewBox="0 0 ([0-9.]+) ([0-9.]+)"/', $svg, $viewBox) !== 1) {
            throw new \RuntimeException('SVG QR invalide : viewBox introuvable.');
        }
        $tailleExterne = (float) $viewBox[1];

        preg_match_all(
            '/M([0-9.]+),([0-9.]+)L([0-9.]+),([0-9.]+)L([0-9.]+),([0-9.]+)L([0-9.]+),([0-9.]+)Z/',
            $svg,
            $rectangles,
            \PREG_SET_ORDER,
        );
        if ($rectangles === []) {
            throw new \RuntimeException('SVG QR invalide : aucun module noir trouvé dans le path.');
        }

        $echelle = self::ECHELLE_PX_PAR_UNITE_SVG;
        $tailleImage = (int) ceil($tailleExterne) * $echelle;
        $pixels = array_fill(0, $tailleImage * $tailleImage, 255);

        foreach ($rectangles as $rect) {
            $gauche = (int) round(((float) $rect[1]) * $echelle);
            $haut = (int) round(((float) $rect[2]) * $echelle);
            $droite = (int) round(((float) $rect[5]) * $echelle);
            $bas = (int) round(((float) $rect[6]) * $echelle);

            for ($y = max(0, $haut); $y < min($tailleImage, $bas); ++$y) {
                for ($x = max(0, $gauche); $x < min($tailleImage, $droite); ++$x) {
                    $pixels[$y * $tailleImage + $x] = 0;
                }
            }
        }

        $source = new RasterLuminanceSource($pixels, $tailleImage, $tailleImage);
        $bitmap = new BinaryBitmap(new HybridBinarizer($source));
        $resultat = (new QRCodeReader())->decode($bitmap);

        return $resultat->toString();
    }
}
