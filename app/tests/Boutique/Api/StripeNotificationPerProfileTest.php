<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Boutique\DataFixtures\BoutiqueFixtures;
use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Entity\SuiviCommandeEnLigne;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\StripeAccountConfig;
use App\Compta\Enum\StripeKeyMode;
use App\Compta\Enum\TypeExploitant;
use App\Compta\Service\StripeKeyEncryptor;
use App\DataFixtures\SocleFixtures;
use App\Offre\Entity\Produit;
use App\Organisation\Entity\Etablissement;
use App\Tests\Boutique\BoutiqueApiTestCase;
use App\Tests\Boutique\Support\FakeStripeServer;
use App\Vente\Entity\Vente;
use App\Vente\Enum\StatutVente;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;

/**
 * UNE ADRESSE DE NOTIFICATION PAR EXPLOITANT (D3-B de #365, G-3 et G-4) — la route publique, le vrai adaptateur, un
 * faux serveur Stripe qui modélise des comptes.
 *
 * Règle : rien n'est lu sur la commande avant la signature ; la seule lecture permise est la ligne de configuration
 * que l'adresse désigne. Tout échec avant la signature rend le MÊME 400. Une signature prouvée mais une commande qui
 * n'est pas de cette adresse rend 404 `inconnu`. Les secrets sont générés à l'exécution ; chaque assertion
 * d'absence (aucun appel, aucune requête) a son témoin positif.
 */
final class StripeNotificationPerProfileTest extends BoutiqueApiTestCase
{
    private const BASE = '/api/boutique/paiement-en-ligne/notification/stripe';

    /** @var array<string, array{0: string|false, 1: mixed, 2: mixed, 3: bool, 4: bool}> */
    private array $envBefore = [];
    private string $keyA = '';
    private string $keyB = '';
    private string $keyGlobal = '';
    private string $secretA = '';
    private string $secretB = '';
    private string $secretGlobal = '';

    protected function setUp(): void
    {
        $this->keyA = 'sk_test_' . bin2hex(random_bytes(12));
        $this->keyB = 'sk_test_' . bin2hex(random_bytes(12));
        $this->keyGlobal = 'sk_test_' . bin2hex(random_bytes(12));
        $this->secretA = 'whsec_' . bin2hex(random_bytes(16));
        $this->secretB = 'whsec_' . bin2hex(random_bytes(16));
        $this->secretGlobal = 'whsec_' . bin2hex(random_bytes(16));
        $this->setEnv('STRIPE_SECRET_KEY', $this->keyGlobal);
        $this->setEnv('STRIPE_WEBHOOK_SECRET', $this->secretGlobal);
        $this->setEnv('BOUTIQUE_BASE_URL', 'https://boutique.example.test');
        FakeStripeServer::reset();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        foreach ($this->envBefore as $name => [$getenv, $env, $server, $inEnv, $inServer]) {
            $getenv === false ? putenv($name) : putenv($name . '=' . $getenv);
            $inEnv ? $_ENV[$name] = $env : $_ENV = array_diff_key($_ENV, [$name => 1]);
            $inServer ? $_SERVER[$name] = $server : $_SERVER = array_diff_key($_SERVER, [$name => 1]);
        }
        $this->envBefore = [];
        parent::tearDown();
    }

    private function setEnv(string $name, ?string $value): void
    {
        $this->envBefore[$name] ??= [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null, \array_key_exists($name, $_ENV), \array_key_exists($name, $_SERVER)];
        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);

            return;
        }
        putenv($name . '=' . $value);
        $_ENV[$name] = $_SERVER[$name] = $value;
    }

    private function siteA(): Etablissement
    {
        return $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);
    }

    private ?string $operatorAId = null;

    /** Désigné par son établissement principal, puis par son id une fois l'établissement A cédé à B. */
    private function operatorA(): ProfilExploitant
    {
        if ($this->operatorAId !== null) {
            return $this->entite(ProfilExploitant::class, ['id' => $this->operatorAId]);
        }
        $operator = $this->entite(ProfilExploitant::class, ['etablissementPrincipal' => $this->siteA()]);
        $this->operatorAId = (string) $operator->getId();

        return $operator;
    }

    private function operatorB(): ProfilExploitant
    {
        return $this->entite(ProfilExploitant::class, ['etablissementPrincipal' => $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_B_NOM])]);
    }

    private function bothPrivate(): void
    {
        $this->operatorA()->setType(TypeExploitant::GroupePrive);
        $this->operatorB()->setType(TypeExploitant::GroupePrive);
        $this->em()->flush();
    }

    /** Le profil B (et non plus A) couvre l'établissement A : la commande de la vitrine A est servie par B. */
    private function moveSiteAToOperatorB(): void
    {
        $this->operatorA()
            ->setEtablissementPrincipal($this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_EDITEUR_NOM]))
            ->setEtablissementsRattaches([]);
        $this->operatorB()->addEtablissementRattache($this->siteA());
        $this->em()->flush();
    }

    private function storeAccount(ProfilExploitant $operator, string $key, ?string $webhookSecret): void
    {
        $encryptor = new StripeKeyEncryptor((string) ($_ENV['STRIPE_KEYS_ENCRYPTION_KEY'] ?? getenv('STRIPE_KEYS_ENCRYPTION_KEY')));
        $config = (new StripeAccountConfig())
            ->setBusinessProfile($operator)
            ->setSecretKeyEncrypted($encryptor->encrypt($key))
            ->setKeyMode(StripeKeyMode::Test);
        if ($webhookSecret !== null) {
            $config->setWebhookSecretEncrypted($encryptor->encrypt($webhookSecret));
        }
        $this->em()->persist($config);
        $this->em()->flush();
    }

    /** A et B ont chacun leur clé et leur secret ; les deux comptes existent chez Stripe. */
    private function bothWithAccounts(): void
    {
        $this->bothPrivate();
        $this->storeAccount($this->operatorA(), $this->keyA, $this->secretA);
        $this->storeAccount($this->operatorB(), $this->keyB, $this->secretB);
        FakeStripeServer::registerKey($this->keyA, 'acct_a');
        FakeStripeServer::registerKey($this->keyB, 'acct_b');
        FakeStripeServer::registerKey($this->keyGlobal, 'acct_global');
    }

    private function addressOf(ProfilExploitant $operator): string
    {
        return self::BASE . '/' . $operator->getId();
    }

    private function signature(string $body, string $secret, ?int $at = null): string
    {
        $t = $at ?? time();

        return 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, $secret);
    }

    private function event(string $reference, string $type = 'checkout.session.completed'): string
    {
        return json_encode(['type' => $type, 'data' => ['object' => ['id' => $reference, 'payment_status' => 'paid']]], \JSON_THROW_ON_ERROR);
    }

    private function post(Client $client, string $url, string $body, ?string $signedWith): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        $headers = $signedWith === null ? [] : ['Stripe-Signature' => $this->signature($body, $signedWith)];

        return $client->request('POST', $url, ['headers' => $headers, 'body' => $body]);
    }

    /** @return array{0: Client, 1: string, 2: string} client, id du panier, référence Stripe (payée) */
    private function paidOrder(): array
    {
        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_SIMPLE_CODE]);
        [$client, $panierId, $jeton] = $this->ouvrirPanierInviteA();
        $entete = [PanierProprietaireGuard::HEADER => $jeton];
        $panier = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/lignes', ['headers' => $entete, 'json' => ['produit' => (string) $produit->getId(), 'quantite' => 1]])->toArray();
        $ligneId = (string) $panier['lignes'][0]['id'];
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/identifier', ['headers' => $entete, 'json' => ['mode' => 'invite', 'email' => 'stripe.notif@example.test']]);
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/consentement', ['headers' => $entete, 'json' => ['mentionVersion' => 'mention-test']]);
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/beneficiaires', ['headers' => $entete, 'json' => ['lignes' => [['ligneId' => $ligneId, 'beneficiaireSimple' => ['nom' => 'Stri', 'prenom' => 'Pe', 'dateNaissance' => '1990-01-01']]]]]);
        $paiement = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/payer', ['headers' => $entete])->toArray();
        self::assertResponseIsSuccessful();
        FakeStripeServer::markPaid($paiement['referenceTransaction']);

        return [$client, $panierId, $paiement['referenceTransaction']];
    }

    private function sale(string $panierId): Vente
    {
        $this->em()->clear();
        $panier = $this->em()->getRepository(PanierEnLigne::class)->find($panierId);
        self::assertInstanceOf(PanierEnLigne::class, $panier);
        $vente = $this->em()->getRepository(SuiviCommandeEnLigne::class)->findOneBy(['panierOrigine' => $panier])?->getVente();
        self::assertInstanceOf(Vente::class, $vente);

        return $vente;
    }

    private function holder(): DebugDataHolder
    {
        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);

        return $holder;
    }

    /** @return list<string> */
    private function queries(DebugDataHolder $holder): array
    {
        return array_column(array_merge([], ...array_values($holder->getData())), 'sql');
    }

    public function testTheOperatorsAddressSignedByTheOperatorSettlesItsOrderAnonymously(): void
    {
        $this->bothWithAccounts();
        [$client, $panierId, $reference] = $this->paidOrder();

        $response = $this->post($client, $this->addressOf($this->operatorA()), $this->event($reference), $this->secretA);

        self::assertResponseIsSuccessful();
        self::assertSame('confirme', $response->toArray()['statut']);
        self::assertSame(StatutVente::Validee, $this->sale($panierId)->getStatut());
        self::assertSame([$this->keyA, $this->keyA], FakeStripeServer::keysUsed(), 'opened and verified with the operator\'s own key');
    }

    public function testTheGlobalAddressStillServesAnOperatorWithoutAKey(): void
    {
        $this->bothPrivate();
        FakeStripeServer::registerKey($this->keyGlobal, 'acct_global');
        [$client, $panierId, $reference] = $this->paidOrder();

        $response = $this->post($client, self::BASE, $this->event($reference), $this->secretGlobal);

        self::assertResponseIsSuccessful();
        self::assertSame('confirme', $response->toArray()['statut']);
        self::assertSame(StatutVente::Validee, $this->sale($panierId)->getStatut());
        self::assertSame([$this->keyGlobal, $this->keyGlobal], FakeStripeServer::keysUsed());
    }

    /**
     * Tout refus avant la signature est le même 400, ne touche pas à Stripe, et ne lit RIEN d'autre que la ligne
     * de configuration désignée (au plus une requête, sur sa table).
     */
    public function testEveryRefusalBeforeTheSignatureIsTheSame400WithoutCommerceOrStripeAccess(): void
    {
        $this->bothWithAccounts();
        // B sans secret de webhook : une configuration qui ne peut rien authentifier.
        $this->em()->getConnection()->executeStatement('UPDATE accounting_stripe_account_config SET webhook_secret_encrypted = NULL WHERE business_profile_id = ?', [$this->operatorB()->getId()->toBinary()]);
        [$client, , $reference] = $this->paidOrder();
        $body = $this->event($reference);
        $holder = $this->holder();

        // Témoin positif : une notification valide, elle, lit la commande et appelle Stripe.
        $holder->reset();
        $callsBefore = \count(FakeStripeServer::calls());
        $this->post($client, $this->addressOf($this->operatorA()), $body, $this->secretA);
        self::assertResponseIsSuccessful();
        self::assertGreaterThan($callsBefore, \count(FakeStripeServer::calls()), 'positive witness: Stripe is called');
        self::assertGreaterThan(1, \count($this->queries($holder)), 'positive witness: the order is read');

        $foreignBodyAltered = $body . ' ';
        $cases = [
            'id inconnu' => [self::BASE . '/' . \Symfony\Component\Uid\Uuid::v4()->toRfc4122(), $body, $this->secretA, null],
            'id mal formé' => [self::BASE . '/zzz', $body, $this->secretA, null],
            'uuid valide suivi d\'un saut de ligne' => [self::BASE . '/' . \Symfony\Component\Uid\Uuid::v4()->toRfc4122() . '%0A', $body, $this->secretA, null],
            'id point-point (double encodage)' => [self::BASE . '/%252e%252e', $body, $this->secretA, null],
            'id de 101 caractères' => [self::BASE . '/' . str_repeat('a', 101), $body, $this->secretA, null],
            'exploitant sans secret' => [$this->addressOf($this->operatorB()), $body, $this->secretB, null],
            'signature de B sur l\'adresse de A' => [$this->addressOf($this->operatorA()), $body, $this->secretB, null],
            'signature absente' => [$this->addressOf($this->operatorA()), $body, null, null],
            'corps altéré' => [$this->addressOf($this->operatorA()), $foreignBodyAltered, $this->secretA, $this->signature($body, $this->secretA)],
            'secret global sur l\'adresse de A' => [$this->addressOf($this->operatorA()), $body, $this->secretGlobal, null],
        ];
        $outcomes = [];
        foreach ($cases as $label => [$url, $sent, $signedWith, $forcedHeader]) {
            $holder->reset();
            $callsBefore = \count(FakeStripeServer::calls());
            $headers = $forcedHeader !== null ? ['Stripe-Signature' => $forcedHeader] : ($signedWith === null ? [] : ['Stripe-Signature' => $this->signature($sent, $signedWith)]);

            $response = $client->request('POST', $url, ['headers' => $headers, 'body' => $sent]);

            $outcomes[$label] = [$response->getStatusCode(), $response->getContent(false)];
            self::assertSame(400, $response->getStatusCode(), $label);
            self::assertSame($callsBefore, \count(FakeStripeServer::calls()), $label . ' : aucun appel à Stripe');
            $sql = $this->queries($holder);
            self::assertLessThanOrEqual(1, \count($sql), $label . ' : au plus une requête');
            foreach ($sql as $query) {
                self::assertStringContainsString('accounting_stripe_account_config', $query, $label . ' : seule la ligne de configuration est lue');
            }
        }
        self::assertCount(1, array_unique(array_map('serialize', $outcomes)), 'même code, même corps pour tous les refus');
        self::assertSame('{"statut":"refuse"}', $outcomes['id inconnu'][1]);
    }

    public function testASignatureOfTheRightAccountNeverSettlesAnotherAccountsOrder(): void
    {
        $this->bothWithAccounts();
        [$client, $panierId, $reference] = $this->paidOrder();   // commande servie par A
        $this->moveSiteAToOperatorB();
        [, $panierB, $referenceB] = $this->paidOrder();           // commande servie par B

        $foreign = $this->post($client, $this->addressOf($this->operatorA()), $this->event($referenceB), $this->secretA);
        self::assertSame(404, $foreign->getStatusCode());
        self::assertSame('{"statut":"inconnu"}', $foreign->getContent(false));

        $globalSigned = $this->post($client, self::BASE, $this->event($referenceB), $this->secretGlobal);
        self::assertSame(404, $globalSigned->getStatusCode(), 'signed by the global secret for an operator that has its own key');
        self::assertSame('{"statut":"inconnu"}', $globalSigned->getContent(false));

        self::assertSame(StatutVente::EnCours, $this->sale($panierB)->getStatut(), 'the order of B is untouched');
        self::assertSame(StatutVente::EnCours, $this->sale($panierId)->getStatut());
        // Stripe n'a vu AUCUN appel de vérification pour ces deux notifications : seuls les deux « payer » ont ouvert une session.
        self::assertSame([$this->keyA, $this->keyB], FakeStripeServer::keysUsed());
        self::assertNotSame($reference, $referenceB);

        // Témoin positif : la bonne adresse, avec le bon secret, règle bien la commande de B.
        $good = $this->post($client, $this->addressOf($this->operatorB()), $this->event($referenceB), $this->secretB);
        self::assertSame(200, $good->getStatusCode());
        self::assertSame(StatutVente::Validee, $this->sale($panierB)->getStatut());
    }

    public function testAnOperatorsSignatureNeverSettlesAnOrderPaidThroughTheGlobalAccount(): void
    {
        $this->bothPrivate();
        $this->storeAccount($this->operatorA(), $this->keyA, $this->secretA);   // A a sa clé ; B n'en a pas
        FakeStripeServer::registerKey($this->keyA, 'acct_a');
        FakeStripeServer::registerKey($this->keyGlobal, 'acct_global');
        $this->moveSiteAToOperatorB();
        [$client, $panierId, $reference] = $this->paidOrder();   // servie par B, sans clé : compte global

        $this->post($client, $this->addressOf($this->operatorA()), $this->event($reference), $this->secretA);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(StatutVente::EnCours, $this->sale($panierId)->getStatut());
        self::assertSame([$this->keyGlobal], FakeStripeServer::keysUsed(), 'only the opening call, no verification with another account');
        // Témoin positif : l'adresse globale règle cette même commande.
        $this->post($client, self::BASE, $this->event($reference), $this->secretGlobal);
        self::assertResponseIsSuccessful();
    }

    /** Le témoin de G-10 : un événement de test signé d'un autre type rend 404 « inconnu » — la signature est donc prouvée. */
    public function testASignedEventOfAnotherTypeOnTheOperatorsAddressIsUnknownNotRefused(): void
    {
        $this->bothWithAccounts();
        [$client] = $this->paidOrder();

        $response = $this->post($client, $this->addressOf($this->operatorA()), '{"type":"charge.succeeded","data":{"object":{"id":"ch_1"}}}', $this->secretA);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('{"statut":"inconnu"}', $response->getContent(false));
        // Et la même chose mal signée est un 400 : les deux réponses ne se confondent pas.
        $this->post($client, $this->addressOf($this->operatorA()), '{"type":"charge.succeeded","data":{"object":{"id":"ch_1"}}}', $this->secretB);
        self::assertResponseStatusCodeSame(400);
    }

    public function testAnOperatorAddressOnAnUnsignedProviderIsUnknown(): void
    {
        $this->bothWithAccounts();
        [$client] = $this->paidOrder();

        $client->request('POST', '/api/boutique/paiement-en-ligne/notification/payfip/' . $this->operatorA()->getId(), ['body' => 'IdOp=abc']);

        self::assertResponseStatusCodeSame(404);
    }

    /** Conséquence assumée (D-8) : un déploiement sans aucune clé Stripe répond 400, plus 404 — la signature est la porte. */
    public function testADeploymentWithoutAnyStripeKeyAnswers400NotFound(): void
    {
        $this->setEnv('STRIPE_SECRET_KEY', null);
        $this->setEnv('STRIPE_WEBHOOK_SECRET', null);
        $this->bothPrivate();
        $client = static::createClient();
        $body = $this->event('cs_test_' . bin2hex(random_bytes(8)));

        $global = $this->post($client, self::BASE, $body, 'whsec_' . bin2hex(random_bytes(16)));
        $operator = $this->post($client, $this->addressOf($this->operatorA()), $body, 'whsec_' . bin2hex(random_bytes(16)));

        self::assertSame(400, $global->getStatusCode());
        self::assertSame(400, $operator->getStatusCode());
        self::assertSame('{"statut":"refuse"}', $global->getContent(false));
    }
}
