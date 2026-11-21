<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Boutique\DataFixtures\BoutiqueFixtures;
use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Entity\SuiviCommandeEnLigne;
use App\Boutique\Paiement\Stripe\StripeCheckoutAdapter;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Enum\TypeExploitant;
use App\Offre\Entity\Produit;
use App\Organisation\Entity\Etablissement;
use App\DataFixtures\SocleFixtures;
use App\Tests\Boutique\BoutiqueApiTestCase;
use App\Tests\Boutique\Support\FakeStripeServer;
use App\Vente\Entity\Vente;
use App\Vente\Enum\StatutVente;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;

/**
 * STRIPE DE BOUT EN BOUT, SIGNATURE DE WEBHOOK COMPRISE — le tunnel de la boutique, le vrai adaptateur, une
 * doublure de l'API Stripe (`FakeStripeServer`). La notification est une route publique : sans signature
 * valide et récente, elle est refusée (400 identique) AVANT toute lecture en base et tout appel à Stripe.
 *
 * Les secrets sont générés à l'exécution, jamais littéraux. La signature est calculée avec l'heure réelle
 * (la fenêtre de tolérance se teste à l'unité, `StripeSignatureCheckerTest`).
 */
final class StripeOnlinePaymentTest extends BoutiqueApiTestCase
{
    private const NOTIFICATION_URL = '/api/boutique/paiement-en-ligne/notification/stripe';

    /** @var array<string, array{0: string|false, 1: mixed, 2: mixed, 3: bool, 4: bool}> */
    private array $envAvant = [];
    private string $webhookSecret;

    protected function setUp(): void
    {
        $this->webhookSecret = 'whsec_' . bin2hex(random_bytes(16));
        $this->setEnv('STRIPE_SECRET_KEY', 'sk_test_' . 'FAUSSECLEDETEST' . bin2hex(random_bytes(4)));
        $this->setEnv('STRIPE_WEBHOOK_SECRET', $this->webhookSecret);
        $this->setEnv('BOUTIQUE_BASE_URL', 'https://boutique.example.test');
        FakeStripeServer::reset();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        foreach ($this->envAvant as $nom => [$getenv, $env, $server, $dansEnv, $dansServer]) {
            $getenv === false ? putenv($nom) : putenv($nom . '=' . $getenv);
            if ($dansEnv) {
                $_ENV[$nom] = $env;
            } else {
                unset($_ENV[$nom]);
            }
            if ($dansServer) {
                $_SERVER[$nom] = $server;
            } else {
                unset($_SERVER[$nom]);
            }
        }
        $this->envAvant = [];
        parent::tearDown();
    }

    /** Pose (ou, avec `null`, retire) une variable dans les trois sources, en gardant de quoi la rendre telle quelle. */
    private function setEnv(string $nom, ?string $valeur): void
    {
        $this->envAvant[$nom] ??= [getenv($nom), $_ENV[$nom] ?? null, $_SERVER[$nom] ?? null, \array_key_exists($nom, $_ENV), \array_key_exists($nom, $_SERVER)];
        if ($valeur === null) {
            putenv($nom);
            unset($_ENV[$nom], $_SERVER[$nom]);

            return;
        }
        putenv($nom . '=' . $valeur);
        $_ENV[$nom] = $_SERVER[$nom] = $valeur;
    }

    /** Témoin positif de G-5 : la notification signée confirme la commande, une seule fois, comme avant la signature. */
    public function testASignedNotificationConfirmsTheOrder(): void
    {
        [$client, $panierId, , $paiement] = $this->panierPretAPayer();
        self::assertSame('cb_psp', $paiement['moyen']);
        $reference = $paiement['referenceTransaction'];
        FakeStripeServer::markPaid($reference);

        $reponse = $this->notify($client, $this->event($reference), $this->webhookSecret);

        self::assertResponseIsSuccessful();
        self::assertSame('confirme', $reponse->toArray()['statut']);
        $vente = $this->vente($panierId);
        self::assertSame(StatutVente::Validee, $vente->getStatut());
        self::assertCount(1, $vente->getPaiements());
    }

    public function testABodyAlteredByOneByteIsRefused(): void
    {
        [$client, $panierId, , $paiement] = $this->panierPretAPayer();
        $reference = $paiement['referenceTransaction'];
        FakeStripeServer::markPaid($reference);
        $body = $this->event($reference);
        $header = $this->signatureHeader($body, $this->webhookSecret);

        $reponse = $client->request('POST', self::NOTIFICATION_URL, ['headers' => ['Stripe-Signature' => $header], 'body' => $body . ' ']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(['statut' => 'refuse'], $reponse->toArray(false));
        self::assertSame(StatutVente::EnCours, $this->vente($panierId)->getStatut(), 'a refused notification confirms nothing');
    }

    public function testOneGoodV1AmongSeveralIsAccepted(): void
    {
        [$client, $panierId, , $paiement] = $this->panierPretAPayer();
        $reference = $paiement['referenceTransaction'];
        FakeStripeServer::markPaid($reference);
        $body = $this->event($reference);
        $t = time();
        $header = 't=' . $t . ',v1=' . str_repeat('a', 64) . ',v1=' . hash_hmac('sha256', $t . '.' . $body, $this->webhookSecret);

        $client->request('POST', self::NOTIFICATION_URL, ['headers' => ['Stripe-Signature' => $header], 'body' => $body]);

        self::assertResponseIsSuccessful();
        self::assertSame(StatutVente::Validee, $this->vente($panierId)->getStatut());
    }

    /**
     * Anti-oracle (G-3) : une signature invalide rend le MÊME code et le MÊME corps que la référence existe ou non,
     * et ne touche ni à Stripe ni à la base. Le témoin positif prouve que les deux compteurs savent compter.
     */
    public function testAnInvalidSignatureIsRefusedIdenticallyBeforeAnySqlOrStripeCall(): void
    {
        [$client, $panierId, , $paiement] = $this->panierPretAPayer();
        $existing = $paiement['referenceTransaction'];
        FakeStripeServer::markPaid($existing);
        $wrongSecret = 'whsec_' . bin2hex(random_bytes(16));

        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);

        // Témoin positif : une notification signée valide fait des appels à Stripe ET des requêtes SQL.
        $holder->reset();
        $callsBefore = \count(FakeStripeServer::calls());
        $this->notify($client, $this->event($existing), $this->webhookSecret);
        self::assertResponseIsSuccessful();
        self::assertGreaterThanOrEqual(1, $this->queryCount($holder), 'positive witness: the SQL counter counts');
        self::assertGreaterThan($callsBefore, \count(FakeStripeServer::calls()), 'positive witness: the Stripe double counts');

        $outcomes = [];
        foreach ([$existing, 'cs_test_' . bin2hex(random_bytes(12))] as $reference) {
            $holder->reset();
            $callsBefore = \count(FakeStripeServer::calls());

            $reponse = $this->notify($client, $this->event($reference), $wrongSecret);

            self::assertResponseStatusCodeSame(400);
            $outcomes[] = [$reponse->getStatusCode(), $reponse->getContent(false)];
            self::assertSame($callsBefore, \count(FakeStripeServer::calls()), 'no call to Stripe before the refusal');
            self::assertSame(0, $this->queryCount($holder), 'no SQL query before the refusal');
        }
        self::assertSame($outcomes[0], $outcomes[1], 'same code and same exact body, whether the reference exists or not');
        self::assertSame('{"statut":"refuse"}', $outcomes[0][1]);
    }

    /** Pas de secret de webhook : 400 pour tout, mais la carte reste proposée (la clé suffit à `isConfigured`). */
    public function testWithoutWebhookSecretEveryNotificationIsRefusedButTheCardStaysOffered(): void
    {
        $this->setEnv('STRIPE_WEBHOOK_SECRET', null);
        [$client, $panierId, , $paiement] = $this->panierPretAPayer();
        self::assertSame('cb_psp', $paiement['moyen'], 'the card is still proposed at /payer');
        self::assertTrue(static::getContainer()->get(StripeCheckoutAdapter::class)->isConfiguredFor($this->entite(ProfilExploitant::class, ['etablissementPrincipal' => $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM])])));
        $reference = $paiement['referenceTransaction'];
        FakeStripeServer::markPaid($reference);
        $callsBefore = \count(FakeStripeServer::calls());

        $reponse = $this->notify($client, $this->event($reference), $this->webhookSecret);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('{"statut":"refuse"}', $reponse->getContent(false));
        self::assertSame($callsBefore, \count(FakeStripeServer::calls()));
        self::assertSame(StatutVente::EnCours, $this->vente($panierId)->getStatut());
    }

    /** Signature valide mais événement qui n'est pas `checkout.session.*` : comme aujourd'hui, 404. */
    public function testASignedEventOfAnotherTypeIsUnknown(): void
    {
        [$client] = $this->panierPretAPayer();

        $this->notify($client, '{"type":"charge.succeeded","data":{"object":{"id":"ch_1"}}}', $this->webhookSecret);

        self::assertResponseStatusCodeSame(404);
    }

    private function signatureHeader(string $body, string $secret): string
    {
        $t = time();

        return 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, $secret);
    }

    private function event(string $reference): string
    {
        return json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => $reference, 'payment_status' => 'paid']]], \JSON_THROW_ON_ERROR);
    }

    private function notify(Client $client, string $body, string $secret): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        return $client->request('POST', self::NOTIFICATION_URL, ['headers' => ['Stripe-Signature' => $this->signatureHeader($body, $secret)], 'body' => $body]);
    }

    private function queryCount(DebugDataHolder $holder): int
    {
        return array_sum(array_map('count', $holder->getData()));
    }

    /** @return array{0: Client, 1: string, 2: array<string, string>, 3: array<string, mixed>} */
    private function panierPretAPayer(): array
    {
        // La vitrine A est en régie directe (PayFiP) : on la passe en exploitant privé pour que Stripe serve.
        $etabA = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);
        foreach ($this->em()->getRepository(ProfilExploitant::class)->findAll() as $profil) {
            if ($profil->couvre($etabA)) {
                $profil->setType(TypeExploitant::GroupePrive);
            }
        }
        $this->em()->flush();

        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_SIMPLE_CODE]);
        [$client, $panierId, $jeton] = $this->ouvrirPanierInviteA();
        $entete = [PanierProprietaireGuard::HEADER => $jeton];

        $panier = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/lignes', ['headers' => $entete, 'json' => ['produit' => (string) $produit->getId(), 'quantite' => 1]])->toArray();
        $ligneId = (string) $panier['lignes'][0]['id'];
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/identifier', ['headers' => $entete, 'json' => ['mode' => 'invite', 'email' => 'stripe.retour@example.test']]);
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/consentement', ['headers' => $entete, 'json' => ['mentionVersion' => 'mention-test']]);
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/beneficiaires', ['headers' => $entete, 'json' => ['lignes' => [['ligneId' => $ligneId, 'beneficiaireSimple' => ['nom' => 'Stri', 'prenom' => 'Pe', 'dateNaissance' => '1990-01-01']]]]]);

        $paiement = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/payer', ['headers' => $entete])->toArray();
        self::assertResponseIsSuccessful();

        return [$client, $panierId, $entete, $paiement];
    }

    private function vente(string $panierId): Vente
    {
        $this->em()->clear();
        $panier = $this->em()->getRepository(PanierEnLigne::class)->find($panierId);
        self::assertInstanceOf(PanierEnLigne::class, $panier);
        $vente = $this->em()->getRepository(SuiviCommandeEnLigne::class)->findOneBy(['panierOrigine' => $panier])?->getVente();
        self::assertInstanceOf(Vente::class, $vente);

        return $vente;
    }
}
