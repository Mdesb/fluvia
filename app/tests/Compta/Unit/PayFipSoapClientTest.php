<?php

declare(strict_types=1);

namespace App\Tests\Compta\Unit;

use App\Compta\PayFip\PayFipConfiguration;
use App\Compta\PayFip\PayFipException;
use App\Compta\PayFip\PayFipPaymentRequest;
use App\Compta\PayFip\PayFipSoapClient;
use App\Tests\Compta\Support\FakePayFipServer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Le client SOAP PayFiP (#339) contre la doublure du service, et contre des réponses écrites à la main
 * aux formes que publie le service (SOAP 1.1, `return` non qualifié, `FonctionnelleErreur` en faute).
 */
final class PayFipSoapClientTest extends TestCase
{
    protected function setUp(): void
    {
        FakePayFipServer::reset();
    }

    private static function configuration(string $mode = 'W'): PayFipConfiguration
    {
        return new PayFipConfiguration('999123', $mode, 'https://boutique.example.test/notif');
    }

    private static function demande(string $mode = 'W', int $centimes = 1250): PayFipPaymentRequest
    {
        return PayFipPaymentRequest::forOrder(self::configuration($mode), 'SYS-20261105-T00012', 'fallback', $centimes, 'acheteur@example.test', 'https://boutique.example.test/b/piscine/retour-paiement', new \DateTimeImmutable('2026-11-05 10:00', new \DateTimeZone('Europe/Paris')));
    }

    public function testLEnveloppeEnvoyeeEstCelleDuServicePaiementSecurise(): void
    {
        $envoye = null;
        $http = new MockHttpClient(function (string $methode, string $url, array $options) use (&$envoye): MockResponse {
            $envoye = ['methode' => $methode, 'url' => $url, 'options' => $options];

            return (new FakePayFipServer())($methode, $url, $options);
        });
        $client = new PayFipSoapClient($http, self::configuration());

        $idOp = $client->createSecurePayment(self::demande());

        self::assertNotSame('', $idOp);
        self::assertSame('POST', $envoye['methode']);
        self::assertSame('https://www.payfip.gouv.fr/tpa/services/securite', $envoye['url']);
        $entetes = implode("\n", $envoye['options']['headers']);
        self::assertStringContainsString('Content-Type: text/xml; charset=utf-8', $entetes);
        self::assertStringContainsString('SOAPAction: ""', $entetes);

        $xml = new \DOMDocument();
        $xml->loadXML($envoye['options']['body']);
        self::assertSame('http://schemas.xmlsoap.org/soap/envelope/', $xml->documentElement->namespaceURI);
        $operation = $xml->getElementsByTagNameNS(PayFipSoapClient::SERVICE_NAMESPACE, 'creerPaiementSecurise');
        self::assertSame(1, $operation->length);
        $champs = [];
        foreach ($xml->getElementsByTagName('arg0')->item(0)->childNodes as $noeud) {
            if ($noeud instanceof \DOMElement) {
                self::assertNull($noeud->namespaceURI, 'les champs de arg0 ne sont pas qualifiés (XSD sans elementFormDefault)');
                $champs[$noeud->localName] = $noeud->textContent;
            }
        }
        self::assertSame([
            'exer' => '2026',
            'mel' => 'acheteur@example.test',
            'montant' => '1250',
            'numcli' => '999123',
            'objet' => 'Commande en ligne SYS20261105T00012',
            'refdet' => 'SYS20261105T00012',
            'saisie' => 'W',
            'urlnotif' => 'https://boutique.example.test/notif',
            'urlredirect' => 'https://boutique.example.test/b/piscine/retour-paiement',
        ], $champs);
        self::assertSame('https://www.payfip.gouv.fr/tpa/paiementws.web?idop=' . $idOp, $client->paymentPageUrl($idOp));
    }

    public function testLeDetailPayeEstLuChampParChamp(): void
    {
        $client = new PayFipSoapClient(new MockHttpClient(new FakePayFipServer()), self::configuration());
        $idOp = $client->createSecurePayment(self::demande());
        FakePayFipServer::decide($idOp, 'P');

        $detail = $client->fetchPaymentDetail($idOp);

        self::assertTrue($detail->isPaid());
        self::assertSame($idOp, $detail->operationId);
        self::assertSame('999123', $detail->clientNumber);
        self::assertSame(1250, $detail->amountCents());
        self::assertSame('SYS20261105T00012', $detail->debtReference);
        self::assertSame('W', $detail->entryMode);
        self::assertSame('123456', $detail->authorizationNumber);
        self::assertSame('2026-11-05 14:32', $detail->transactedAt()?->format('Y-m-d H:i'));
    }

    public function testRefusEtAbandonNeSontPasDesPaiements(): void
    {
        $client = new PayFipSoapClient(new MockHttpClient(new FakePayFipServer()), self::configuration());
        foreach (['R' => 'isRefused', 'Z' => 'isRefused', 'A' => 'isAbandoned'] as $code => $predicat) {
            $idOp = $client->createSecurePayment(self::demande());
            FakePayFipServer::decide($idOp, $code);
            $detail = $client->fetchPaymentDetail($idOp);
            self::assertFalse($detail->isPaid(), $code);
            self::assertTrue($detail->{$predicat}(), $code);
        }
    }

    public function testLesFautesFonctionnellesRendentLeurCode(): void
    {
        $client = new PayFipSoapClient(new MockHttpClient(new FakePayFipServer()), self::configuration());
        $idOp = $client->createSecurePayment(self::demande());

        try {
            $client->fetchPaymentDetail($idOp);
            self::fail('P5 attendu');
        } catch (PayFipException $e) {
            self::assertSame('P5', $e->faultCode());
            self::assertTrue($e->isResultNotKnownYet());
        }

        try {
            $client->fetchPaymentDetail('inconnu');
            self::fail('P1 attendu');
        } catch (PayFipException $e) {
            self::assertSame(PayFipException::UNKNOWN_OPERATION, $e->faultCode());
        }
    }

    public function testUneReponseNonSoapEstUneErreurPasUnResultat(): void
    {
        foreach (['', '<html>maintenance</html>', '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><x>&e;</x>'] as $corps) {
            $client = new PayFipSoapClient(new MockHttpClient(new MockResponse($corps, ['http_code' => 200])), self::configuration());
            try {
                $client->fetchPaymentDetail('abc');
                self::fail('exception attendue pour : ' . $corps);
            } catch (PayFipException $e) {
                self::assertNull($e->faultCode());
            }
        }
    }

    public function testLaDemandeRespecteLesFormatsDuGuide(): void
    {
        // REFDET : la ponctuation tombe ; trop court, on prend l'identifiant de repli.
        $court = PayFipPaymentRequest::forOrder(self::configuration(), 'A-1', 'Fallback22chars0000000', 1000, 'a@example.test', 'https://x.example.test/r', new \DateTimeImmutable());
        self::assertSame('Fallback22chars0000000', $court->debtReference);

        // En test (T) et en activation (X), PayFiP n'accepte que des euros ronds.
        foreach (['T', 'X'] as $mode) {
            try {
                self::demande($mode, 1250);
                self::fail('centimes refusés en mode ' . $mode);
            } catch (PayFipException $e) {
                self::assertStringContainsString('euros ronds', $e->getMessage());
            }
            self::assertSame(1200, self::demande($mode, 1200)->amountCents);
        }

        // Montant : 7 chiffres au plus (factures < 100 000 €).
        $this->expectException(PayFipException::class);
        self::demande('W', 10_000_000);
    }

    public function testUneConfigurationIncompleteResteEteinte(): void
    {
        self::assertFalse((new PayFipConfiguration())->isConfigured());
        self::assertFalse((new PayFipConfiguration('12345', 'W', 'https://a.example.test/n'))->isConfigured(), 'NUMCLI à 5 chiffres');
        self::assertFalse((new PayFipConfiguration('123456', 'Q', 'https://a.example.test/n'))->isConfigured(), 'mode inconnu');
        self::assertFalse((new PayFipConfiguration('123456', 'W', ''))->isConfigured(), 'sans URL de notification');
        self::assertTrue((new PayFipConfiguration('123456', null, 'https://a.example.test/n'))->isConfigured());
        self::assertSame('T', (new PayFipConfiguration('123456', null, 'https://a.example.test/n'))->entryMode(), 'mode par défaut : test, jamais le réel');
    }
}
