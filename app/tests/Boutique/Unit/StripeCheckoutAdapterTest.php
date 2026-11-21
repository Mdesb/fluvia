<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Unit;

use App\Boutique\Paiement\InvalidNotificationSignatureException;
use App\Boutique\Paiement\NotificationRejection;
use App\Boutique\Paiement\OnlinePaymentContext;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Enum\TypeExploitant;
use App\Boutique\Paiement\SignedNotificationInterface;
use App\Boutique\Paiement\Stripe\StripeCheckoutAdapter;
use App\Tests\Boutique\Support\StripeUnitWiring;
use App\Vente\Enum\StatutTPE;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * Adaptateur PSP CB des exploitants privés (#339, prestataire soumis au choix de Maxime) contre une
 * doublure de l'API Stripe Checkout. Mêmes témoins que PayFiP : payé confirme, non payé ne confirme pas,
 * une référence ou une réponse qui n'est pas la nôtre n'atteste rien.
 */
final class StripeCheckoutAdapterTest extends TestCase
{
    private const CLE = 'sk_test_' . 'FAUSSECLEDETEST0000';

    /** @param list<array{0: string, 1: string, 2: array<string, mixed>}> $appels */
    private function adaptateur(callable $serveur, array &$appels = []): StripeCheckoutAdapter
    {
        $http = new MockHttpClient(function (string $methode, string $url, array $options) use ($serveur, &$appels): MockResponse {
            $appels[] = [$methode, $url, $options];

            return $serveur($methode, $url, $options);
        });

        return $this->adapterWith($http, self::CLE);
    }

    /** Adaptateur dont la seule source de compte est l'environnement (aucune clé d'exploitant en base : stub de dépôt vide). */
    private function adapterWith(MockHttpClient $http, ?string $key = null, ?string $webhookSecret = null): StripeCheckoutAdapter
    {
        return new StripeCheckoutAdapter($http, new NullLogger(), StripeUnitWiring::resolver($this, $key, $webhookSecret));
    }

    private function profil(): ProfilExploitant
    {
        return (new ProfilExploitant())->setType(TypeExploitant::GroupePrive);
    }

    private function contexte(): OnlinePaymentContext
    {
        return new OnlinePaymentContext('SYS-T00001', '', $this->profil());
    }

    /** @param array<string, mixed> $corps */
    private static function json(array $corps, int $code = 200): MockResponse
    {
        return new MockResponse(json_encode($corps, \JSON_THROW_ON_ERROR), ['http_code' => $code, 'response_headers' => ['content-type' => 'application/json']]);
    }

    public function testSansContexteLaVerificationNAtranRienEtNAppellePasStripe(): void
    {
        $appels = [];
        $adaptateur = $this->adaptateur(fn () => self::json(['id' => 'cs_test_abc', 'payment_status' => 'paid', 'status' => 'complete', 'amount_total' => 1250, 'currency' => 'eur']), $appels);

        self::assertNull($adaptateur->verifierRetour('cs_test_abc', 1250, []), 'fail closed: no profile, no account to look in');
        self::assertSame([], $appels, 'Stripe is not called');
        // Témoin positif : avec le contexte, la même session payée est attestée.
        self::assertSame(StatutTPE::Accepte, $adaptateur->verifierRetour('cs_test_abc', 1250, [], $this->contexte())?->statut);
        self::assertCount(1, $appels);
    }

    public function testLInitiationOuvreUneSessionCheckoutHebergee(): void
    {
        $appels = [];
        $adaptateur = $this->adaptateur(fn () => self::json(['id' => 'cs_test_abc', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_abc']), $appels);
        $vente = Uuid::v4();

        $initiation = $adaptateur->initierPaiement($vente, 1250, 'https://boutique.example.test/b/patinoire-b/retour-paiement', new OnlinePaymentContext('SYS-T00001', 'acheteur@example.test', $this->profil()));

        self::assertSame('cs_test_abc', $initiation->referenceTransaction);
        self::assertSame('https://checkout.stripe.com/c/pay/cs_test_abc', $initiation->urlRedirection);
        self::assertNull($initiation->simulationReceipts);
        [$methode, $url, $options] = $appels[0];
        self::assertSame('POST', $methode);
        self::assertSame('https://api.stripe.com/v1/checkout/sessions', $url);
        self::assertContains('Authorization: Bearer ' . self::CLE, $options['headers']);
        parse_str((string) $options['body'], $corps);
        self::assertSame('payment', $corps['mode']);
        self::assertSame('1250', $corps['line_items'][0]['price_data']['unit_amount']);
        self::assertSame('eur', $corps['line_items'][0]['price_data']['currency']);
        self::assertSame((string) $vente, $corps['client_reference_id']);
        self::assertSame('https://boutique.example.test/b/patinoire-b/retour-paiement?session_id={CHECKOUT_SESSION_ID}', $corps['success_url']);
        self::assertSame('acheteur@example.test', $corps['customer_email']);
    }

    public function testUneSessionPayeeAttesteLeMontantRenduParStripe(): void
    {
        $adaptateur = $this->adaptateur(fn () => self::json(['id' => 'cs_test_abc', 'payment_status' => 'paid', 'status' => 'complete', 'amount_total' => 1250, 'currency' => 'eur']));

        $resultat = $adaptateur->verifierRetour('cs_test_abc', 1250, ['statut' => 'refuse'], $this->contexte());

        self::assertNotNull($resultat);
        self::assertSame(StatutTPE::Accepte, $resultat->statut);
        self::assertSame(1250, $resultat->montantCentimes, 'le montant rendu est celui de Stripe : le règlement le confronte à l\'initié');
    }

    public function testUneSessionOuverteOuExpireeNeConfirmeRien(): void
    {
        $ouverte = $this->adaptateur(fn () => self::json(['id' => 'cs_test_abc', 'payment_status' => 'unpaid', 'status' => 'open', 'amount_total' => 1250, 'currency' => 'eur']));
        self::assertSame(StatutTPE::Timeout, $ouverte->verifierRetour('cs_test_abc', 1250, ['statut' => 'accepte'], $this->contexte())?->statut);

        $expiree = $this->adaptateur(fn () => self::json(['id' => 'cs_test_abc', 'payment_status' => 'unpaid', 'status' => 'expired', 'amount_total' => 1250, 'currency' => 'eur']));
        self::assertSame(StatutTPE::Annule, $expiree->verifierRetour('cs_test_abc', 1250, [], $this->contexte())?->statut);
    }

    public function testUnRetourFalsifieNAttesteRien(): void
    {
        $appels = [];
        $inconnue = $this->adaptateur(fn () => self::json(['error' => ['message' => 'No such checkout.session']], 404), $appels);
        self::assertNull($inconnue->verifierRetour('cs_test_inventee', 1250, ['payment_status' => 'paid'], $this->contexte()));

        // Une référence qui n'a pas la forme d'une session n'est même pas demandée à Stripe.
        self::assertNull($inconnue->verifierRetour('../v1/charges', 1250, [], $this->contexte()));
        self::assertCount(1, $appels);

        $autreSession = $this->adaptateur(fn () => self::json(['id' => 'cs_test_autre', 'payment_status' => 'paid', 'status' => 'complete', 'amount_total' => 1250, 'currency' => 'eur']));
        self::assertNull($autreSession->verifierRetour('cs_test_abc', 1250, [], $this->contexte()));

        $autreDevise = $this->adaptateur(fn () => self::json(['id' => 'cs_test_abc', 'payment_status' => 'paid', 'status' => 'complete', 'amount_total' => 1250, 'currency' => 'usd']));
        self::assertNull($autreDevise->verifierRetour('cs_test_abc', 1250, [], $this->contexte()));
    }

    public function testLaNotificationNeDonneQuUneReference(): void
    {
        $adaptateur = $this->adaptateur(fn () => self::json([]));
        $evenement = static fn (array $e): Request => new Request(content: json_encode($e, \JSON_THROW_ON_ERROR));

        self::assertSame('cs_test_abc', $adaptateur->referenceFromNotification($evenement(['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_test_abc', 'payment_status' => 'paid']]])));
        self::assertNull($adaptateur->referenceFromNotification($evenement(['type' => 'charge.succeeded', 'data' => ['object' => ['id' => 'ch_1']]])));
        self::assertNull($adaptateur->referenceFromNotification(new Request(content: 'pas du json')));
    }

    public function testSansCleSecreteLAdaptateurResteEteint(): void
    {
        self::assertFalse($this->adapterWith(new MockHttpClient())->isConfiguredFor($this->profil()));
        self::assertFalse(($this->adapterWith(new MockHttpClient(), 'pk_test_publique'))->isConfiguredFor($this->profil()), 'une clé publique ne suffit pas');
        self::assertTrue($this->adaptateur(fn () => self::json([]))->isConfiguredFor($this->profil()));
    }

    private function signedRequest(string $body, string $secret, int $t): Request
    {
        $header = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, $secret);

        return new Request(content: $body, server: ['HTTP_STRIPE_SIGNATURE' => $header]);
    }

    public function testTheAdapterIsASignedNotificationProvider(): void
    {
        self::assertInstanceOf(SignedNotificationInterface::class, $this->adapterWith(new MockHttpClient()));
    }

    public function testVerifyNotificationAcceptsASignedBodyAndRefusesTheRest(): void
    {
        $secret = 'whsec_' . bin2hex(random_bytes(16));
        $adaptateur = $this->adapterWith(new MockHttpClient(), self::CLE, $secret);
        $body = '{"type":"checkout.session.completed","data":{"object":{"id":"cs_test_abc"}}}';

        $adaptateur->verifyNotification($this->signedRequest($body, $secret, time()));

        $refus = function (Request $request) use ($adaptateur): NotificationRejection {
            try {
                $adaptateur->verifyNotification($request);
            } catch (InvalidNotificationSignatureException $e) {
                return $e->reason;
            }
            self::fail('a rejection was expected');
        };
        $header = (string) $this->signedRequest($body, $secret, time())->headers->get('Stripe-Signature');
        self::assertSame(NotificationRejection::Invalid, $refus(new Request(content: $body . ' ', server: ['HTTP_STRIPE_SIGNATURE' => $header])));
        self::assertSame(NotificationRejection::Missing, $refus(new Request(content: $body)));
        self::assertSame(NotificationRejection::OutOfWindow, $refus($this->signedRequest($body, $secret, time() - 3600)));
    }

    public function testWithoutWebhookSecretEveryNotificationIsRefusedButTheCardStaysOffered(): void
    {
        $secret = 'whsec_' . bin2hex(random_bytes(16));
        $adaptateur = $this->adapterWith(new MockHttpClient(), self::CLE);

        self::assertTrue($adaptateur->isConfiguredFor($this->profil()), 'the missing webhook secret does not switch the card off');
        try {
            $adaptateur->verifyNotification($this->signedRequest('{}', $secret, time()));
            self::fail('a rejection was expected');
        } catch (InvalidNotificationSignatureException $e) {
            self::assertSame(NotificationRejection::NotConfigured, $e->reason);
        }
    }

    /** The rejection carries the enum only: no secret, header or body in its message, trace or string form. */
    public function testTheRejectionCarriesNoSecretHeaderOrBody(): void
    {
        $secret = 'whsec_' . bin2hex(random_bytes(16));
        $adaptateur = $this->adapterWith(new MockHttpClient(), self::CLE, $secret);
        $body = '{"sensible":"corps-de-test"}';
        $header = (string) $this->signedRequest($body . 'x', $secret, time())->headers->get('Stripe-Signature');

        $caught = null;
        try {
            $adaptateur->verifyNotification(new Request(content: $body, server: ['HTTP_STRIPE_SIGNATURE' => $header]));
        } catch (InvalidNotificationSignatureException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'a rejection was expected');
        foreach ([$secret, $header, $body] as $sensible) {
            self::assertStringNotContainsString($sensible, $caught->getMessage());
            self::assertStringNotContainsString($sensible, $caught->getTraceAsString());
            self::assertStringNotContainsString($sensible, (string) $caught);
        }
    }
}
