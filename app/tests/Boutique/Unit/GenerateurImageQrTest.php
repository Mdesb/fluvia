<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Unit;

use App\Boutique\Billet\GenerateurImageQr;
use App\Tests\Boutique\Support\QrSvgDecoder;
use App\Vente\Service\GenerateurCodeSupport;
use PHPUnit\Framework\TestCase;

/**
 * Génération du QR image (US-L8-08, RG-M3-04, CA-12) : l'image produite est **réellement
 * décodable** en le payload attendu — décodage authentique (format info, masque, Reed-Solomon,
 * mode alphanumérique/byte) via un lecteur ZXing pur PHP, sans dépendance à `ext-gd`/`ext-imagick`
 * (absentes de l'image PHP de ce dépôt, cf. `App\Tests\Boutique\Support\QrSvgDecoder`).
 *
 * Les payloads de décodage sont des **littéraux fixes** (mêmes forme/longueur qu'un vrai code de
 * support signé — `App\Vente\Service\GenerateurCodeSupport`) plutôt que générés aléatoirement à
 * chaque exécution : le rendu QR est une fonction pure et déterministe du payload (même bibliothèque
 * `endroid/qr-code` que la production), donc un littéral fixe donne un résultat **stable et
 * reproductible** ; un aléa fraîchement tiré (`genererPourType()`) exposerait en revanche le test aux
 * quelques cas limites connus du décodeur pur-PHP utilisé ici (`khanamiryan/qrcode-detector-decoder`,
 * non garanti à 100 % sur un rendu vectoriel à arêtes nettes, contrairement à un vrai scanner) — cf.
 * rapport du lot.
 */
final class GenerateurImageQrTest extends TestCase
{
    private const PAYLOAD_FIXE = 'QRC-K7QRJXTN2VH4PLQS-9F3A2B7C1D';

    public function testLeFormatDuPayloadEstBienCeluiDuCodeDeSupportSigne(): void
    {
        $generateurCode = new GenerateurCodeSupport('cle-de-test-hmac-support');
        $payload = $generateurCode->genererPourType(\App\Vente\Enum\TypeSupport::Qr);

        self::assertTrue($generateurCode->estCodeSigne($payload));
        self::assertTrue($generateurCode->verifier($payload));
        self::assertMatchesRegularExpression('/^QRC-[0-9A-HJKMNP-TV-Z]{16}-[0-9A-F]{10}$/', $payload);
    }

    public function testLeQrSvgEstDecodableEnLePayloadFourni(): void
    {
        $image = (new GenerateurImageQr())->generer(self::PAYLOAD_FIXE);

        self::assertSame('image/svg+xml', $image->mimeType);
        self::assertNotSame('', $image->contenu);
        self::assertStringStartsWith('<?xml', ltrim($image->contenu));

        // Le SVG doit rester un document XML valide et exploitable (email/PDF).
        $xml = new \SimpleXMLElement($image->contenu);
        self::assertSame('svg', $xml->getName());

        self::assertSame(
            self::PAYLOAD_FIXE,
            QrSvgDecoder::decoder($image->contenu),
            'Le QR image doit se décoder exactement en le payload fourni.',
        );
    }

    public function testDeuxPayloadsDifferentsProduisentDesImagesDifferentesEtDecodablesChacune(): void
    {
        $qr = new GenerateurImageQr();

        $imageA = $qr->generer('BIL-K7QRJXTN2VH4PLQS-9F3A2B7C1D');
        $imageB = $qr->generer('CAR-2B3C4D5E6F7G8H9J-0102030405');

        self::assertNotSame($imageA->contenu, $imageB->contenu);
        self::assertSame('BIL-K7QRJXTN2VH4PLQS-9F3A2B7C1D', QrSvgDecoder::decoder($imageA->contenu));
        self::assertSame('CAR-2B3C4D5E6F7G8H9J-0102030405', QrSvgDecoder::decoder($imageB->contenu));
    }

    public function testDataUriEstDirectementEmbarquableDansUneBaliseImg(): void
    {
        $image = (new GenerateurImageQr())->generer('QRC-TESTDATAURI0000-ABCDEF0123');

        $dataUri = $image->dataUri();
        self::assertStringStartsWith('data:image/svg+xml;base64,', $dataUri);

        $decodee = base64_decode(substr($dataUri, \strlen('data:image/svg+xml;base64,')), true);
        self::assertSame($image->contenu, $decodee);
        self::assertSame('QRC-TESTDATAURI0000-ABCDEF0123', QrSvgDecoder::decoder($decodee));
    }
}
