<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Support;

use Zxing\LuminanceSource;

/**
 * Source de luminance minimale (0..255, tableau ligne-major) pour le décodeur ZXing pur PHP
 * (`khanamiryan/qrcode-detector-decoder`), utilisée **uniquement dans les tests** pour vérifier que le
 * QR SVG produit par `App\Boutique\Billet\GenerateurImageQr` est réellement **décodable** (cf.
 * `QrSvgDecoder`).
 *
 * `Zxing\RGBLuminanceSource` (fournie par la lib) n'est **pas** utilisable ici : son constructeur à
 * 3 arguments délègue à une méthode interne dont l'ordre des paramètres ne correspond pas à l'appel
 * (`RGBLuminanceSource_($pixels, $dataWidth, $dataHeight)` alors que la signature attend
 * `($width, $height, $pixels)`), ce qui casse `getWidth()`/`getHeight()` — bug constaté dans
 * `khanamiryan/qrcode-detector-decoder` 2.0.3. Cette classe évite le problème en implémentant
 * directement le contrat `LuminanceSource` (aucune conversion RGB→luminance nécessaire : le rendu est
 * déjà en noir/blanc).
 */
final class RasterLuminanceSource extends LuminanceSource
{
    /** @param list<int> $luminances valeurs 0..255, ligne-major (index = y * width + x) */
    public function __construct(
        private readonly array $luminances,
        int $width,
        int $height,
    ) {
        parent::__construct($width, $height);
    }

    public function getRow($y, $row = null)
    {
        $width = (int) $this->getWidth();
        $offset = $y * $width;

        return \array_slice($this->luminances, $offset, $width);
    }

    public function getMatrix()
    {
        return $this->luminances;
    }

    public function crop($left, $top, $width, $height): LuminanceSource
    {
        throw new \RuntimeException('RasterLuminanceSource : recadrage non supporté (inutile en test).');
    }

    public function rotateCounterClockwise(): void
    {
        throw new \RuntimeException('RasterLuminanceSource : rotation non supportée (inutile en test).');
    }

    public function rotateCounterClockwise45(): void
    {
        throw new \RuntimeException('RasterLuminanceSource : rotation non supportée (inutile en test).');
    }
}
