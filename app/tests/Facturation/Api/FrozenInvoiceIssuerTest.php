<?php

declare(strict_types=1);

namespace App\Tests\Facturation\Api;

use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\Compta\Enum\VatCategory;
use App\Facturation\Einvoicing\CiiSerializer;
use App\Facturation\Einvoicing\InvoiceHtmlRenderer;
use App\Facturation\Entity\Facture;
use App\Tests\Facturation\FacturationApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * THE ISSUER IS FROZEN AT ISSUANCE (#345).
 *
 * Issue an invoice, change the operator profile (name, address, SIRET, VAT number, logo), reprint:
 * the reprint must show the ORIGINAL issuer, in the PDF header and in the Factur-X XML alike.
 *
 * Control: a draft created after the change shows the NEW issuer. Without it, a renderer that
 * printed a constant, or a profile change that never reached the database, would pass the main test.
 */
final class FrozenInvoiceIssuerTest extends FacturationApiTestCase
{
    public function testAReprintShowsTheOriginalIssuerAfterTheProfileChanged(): void
    {
        // BT-151 is null on the demo VAT rate; the XML refuses to serialise without it (same set-up
        // as `FacturXTelechargementApiTest`).
        $taux = $this->entite(TauxTva::class, ['libelle' => 'Taux normal 20 %']);
        \assert($taux instanceof TauxTva);
        $taux->setVatCategory(VatCategory::Standard);
        $this->em()->flush();

        [$client, $entete] = $this->adminSurA();
        $logoOrigine = self::jpeg('01');
        $this->uploadLogo($client, $entete, $logoOrigine);
        $this->setProfile('Régie des piscines (origine)', '1 rue de l’Origine', '59600', '20004049500017', 'FR44200040495');

        $id = $this->issueInvoice($client, $entete);

        // The profile changes AFTER issuance: new name, address, SIRET, VAT number and logo.
        $logoNouveau = self::jpeg('02');
        self::assertNotSame($logoOrigine, $logoNouveau, 'The two logos must differ, or the logo assertion proves nothing.');
        $this->uploadLogo($client, $entete, $logoNouveau);
        $this->setProfile('Nouvelle dénomination', '99 avenue Nouvelle', '75001', '99988877700011', 'FR00999888777');

        $facture = $this->freshInvoice($id);
        $html = $this->renderer()->render($facture);

        self::assertStringContainsString('Régie des piscines (origine)', $html);
        self::assertStringContainsString('1 rue de l’Origine', $html);
        self::assertStringContainsString('SIRET : 20004049500017', $html);
        self::assertStringContainsString('FR44200040495', $html);
        self::assertStringContainsString(base64_encode($logoOrigine), $html, 'The logo printed at issuance.');

        self::assertStringNotContainsString('Nouvelle dénomination', $html);
        self::assertStringNotContainsString('99 avenue Nouvelle', $html);
        self::assertStringNotContainsString('99988877700011', $html);
        self::assertStringNotContainsString(base64_encode($logoNouveau), $html);

        // The XML embedded in the Factur-X must agree with the PDF, not with today's profile.
        $xml = $this->serializer()->serialize($facture);
        self::assertStringContainsString('Régie des piscines (origine)', $xml);
        self::assertStringContainsString('FR44200040495', $xml);
        self::assertStringNotContainsString('Nouvelle dénomination', $xml);
        self::assertStringNotContainsString('FR00999888777', $xml);

        // Control: a draft follows the live profile, so the change did reach the database.
        $brouillon = $client->request('POST', '/api/factures', $entete + ['json' => $this->corpsFactureDirecte()])->toArray();
        self::assertResponseIsSuccessful();
        $htmlBrouillon = $this->renderer()->render($this->freshInvoice((string) $brouillon['id']));
        self::assertStringContainsString('Nouvelle dénomination', $htmlBrouillon);
        self::assertStringContainsString(base64_encode($logoNouveau), $htmlBrouillon);
        self::assertStringNotContainsString('Régie des piscines (origine)', $htmlBrouillon);
    }

    public function testTheFrozenIssuerCannotBeRewrittenAfterIssuance(): void
    {
        [$client, $entete] = $this->adminSurA();
        $id = $this->issueInvoice($client, $entete);

        $facture = $this->freshInvoice($id);
        $fige = $facture->getEmetteurFige();
        self::assertIsArray($fige, 'Issuance must freeze the issuer.');

        $fige['raisonSociale'] = 'Réécrit après coup';
        $facture->setEmetteurFige($fige);

        $this->expectException(ConflictHttpException::class);
        $this->em()->flush();
    }

    /** @param array<string, mixed> $entete */
    private function issueInvoice(Client $client, array $entete): string
    {
        $brouillon = $client->request('POST', '/api/factures', $entete + ['json' => $this->corpsFactureDirecte()])->toArray();
        self::assertResponseIsSuccessful();
        $emise = $client->request('POST', '/api/factures/' . $brouillon['id'] . '/emettre', $entete)->toArray();
        self::assertResponseIsSuccessful();

        return (string) $emise['id'];
    }

    private function setProfile(string $raisonSociale, string $rue, string $cp, string $siret, string $tva): void
    {
        $em = $this->em();
        $em->clear();
        $profil = $em->getRepository(ProfilExploitant::class)->find(Uuid::fromString($this->idProfilExploitant()));
        self::assertInstanceOf(ProfilExploitant::class, $profil);
        $profil->setRaisonSociale($raisonSociale)
            ->setSiret($siret)
            ->setTvaIntracommunautaire($tva)
            ->setAdresse(['rue' => $rue, 'cp' => $cp, 'ville' => 'Maubeuge', 'pays' => 'FR']);
        $em->flush();
        $em->clear();
    }

    /** @param array<string, mixed> $entete */
    private function uploadLogo(Client $client, array $entete, string $octets): void
    {
        $chemin = (string) tempnam(sys_get_temp_dir(), 'logo');
        file_put_contents($chemin, $octets);
        $entete['extra'] = ['files' => ['file' => new UploadedFile($chemin, 'logo.jpg', 'image/jpeg', null, true)]];
        $client->request('POST', '/api/profil_exploitants/' . $this->idProfilExploitant() . '/logo', $entete);
        self::assertResponseIsSuccessful();
    }

    private function freshInvoice(string $id): Facture
    {
        $em = $this->em();
        $em->clear();
        $facture = $em->getRepository(Facture::class)->find(Uuid::fromString($id));
        self::assertInstanceOf(Facture::class, $facture);

        return $facture;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }

    private function renderer(): InvoiceHtmlRenderer
    {
        /** @var InvoiceHtmlRenderer $r */
        $r = static::getContainer()->get(InvoiceHtmlRenderer::class);

        return $r;
    }

    private function serializer(): CiiSerializer
    {
        /** @var CiiSerializer $s */
        $s = static::getContainer()->get(CiiSerializer::class);

        return $s;
    }

    /** A real 8×8 JPEG; the quantisation byte varies so that two logos have different bytes. */
    private static function jpeg(string $quantisation): string
    {
        return (string) hex2bin('ffd8ffe000104a46494600010100000100010000ffdb004300' . str_repeat($quantisation, 64)
            . 'ffc0000b080008000801011100'
            . 'ffc40014000100' . str_repeat('00', 15) . 'ffc40014100100' . str_repeat('00', 15)
            . 'ffda0008010100003f003fffd9');
    }
}
