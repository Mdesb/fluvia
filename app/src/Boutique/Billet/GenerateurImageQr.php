<?php

declare(strict_types=1);

namespace App\Boutique\Billet;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\SvgWriter;

/**
 * Génère l'image du QR dynamique porté par un billet (US-L8-08, RG-M3-04, repli QR RG-M3-14, CA-12),
 * à partir du `payload` = code de support unique et signé HMAC
 * (`App\Vente\Service\GenerateurCodeSupport::genererPourType()`, déjà utilisé par
 * `App\Vente\Service\ValiderVenteService::creerSupport()` — cette classe ne génère **pas** le payload,
 * seulement son image).
 *
 * Rendu en **SVG** (vectoriel), pas en PNG : l'écriture PNG native d'`endroid/qr-code` (`PngWriter`)
 * s'appuie sur l'extension `ext-gd`, absente de l'image PHP de ce dépôt (aucune modification du
 * `Dockerfile` — hors périmètre de ce lot, cf. rapport). Le SVG ne nécessite aucune extension d'image,
 * reste net à toute résolution et s'embarque tel quel :
 *  - en e-mail HTML (`<img src="data:image/svg+xml;base64,...">`, tous les clients mail modernes) ;
 *  - dans le PDF billet (`GenerateurPdfBillet`, dompdf + `dompdf/php-svg-lib`, testé sans GD).
 */
final class GenerateurImageQr
{
    public function generer(string $payload, int $taillePx = 300, int $margePx = 16): ImageQr
    {
        $resultat = (new Builder(writer: new SvgWriter()))->build(
            data: $payload,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: $taillePx,
            margin: $margePx,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
            foregroundColor: new Color(0, 0, 0),
            backgroundColor: new Color(255, 255, 255),
        );

        return new ImageQr($payload, $resultat->getString(), $resultat->getMimeType());
    }
}
