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
use App\Tests\Support\LogSpy;
use App\Vente\Entity\Vente;
use App\Vente\Enum\StatutVente;

/**
 * UN COMPTE STRIPE PAR EXPLOITANT, DE BOUT EN BOUT (D3-B de #365) — le tunnel de la boutique, le vrai adaptateur,
 * un faux serveur Stripe qui modélise des COMPTES (une session n'existe que dans le compte de la clé qui l'a créée).
 *
 * G-2 : payer et vérifier passent par la clé de l'exploitant de la commande, jamais celle d'un autre.
 * G-3 : sans clé d'exploitant, la clé globale sert ; sans rien, la carte n'est pas proposée par Stripe.
 * G-6 : aucune clé ne figure dans un journal ni dans une réponse, même quand Stripe cite la clé entière.
 *
 * La commande est celle de la vitrine A. Un autre exploitant la sert quand on lui « rattache » l'établissement A et
 * qu'on retire A au profil d'origine : le résolveur choisit alors le profil de la commande, donc son compte.
 * Toutes les clés sont générées à l'exécution.
 */
final class StripeAccountPerProfileTest extends BoutiqueApiTestCase
{
    /** @var array<string, array{0: string|false, 1: mixed, 2: mixed, 3: bool, 4: bool}> */
    private array $envBefore = [];
    private string $keyA = '';
    private string $keyB = '';
    private string $keyGlobal = '';

    protected function setUp(): void
    {
        $this->keyA = 'sk_test_' . bin2hex(random_bytes(12));
        $this->keyB = 'sk_test_' . bin2hex(random_bytes(12));
        $this->keyGlobal = 'sk_test_' . bin2hex(random_bytes(12));
        $this->setEnv('STRIPE_SECRET_KEY', $this->keyGlobal);
        $this->setEnv('STRIPE_WEBHOOK_SECRET', 'whsec_' . bin2hex(random_bytes(16)));
        $this->setEnv('BOUTIQUE_BASE_URL', 'https://boutique.example.test');
        FakeStripeServer::reset();
        LogSpy::reset();

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

    private function operatorA(): ProfilExploitant
    {
        return $this->entite(ProfilExploitant::class, ['etablissementPrincipal' => $this->siteA()]);
    }

    private function operatorB(): ProfilExploitant
    {
        return $this->entite(ProfilExploitant::class, ['etablissementPrincipal' => $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_B_NOM])]);
    }

    /** Les deux exploitants sont privés ; A sert la commande de la vitrine A. */
    private function bothPrivate(): void
    {
        $this->operatorA()->setType(TypeExploitant::GroupePrive);
        $this->operatorB()->setType(TypeExploitant::GroupePrive);
        $this->em()->flush();
    }

    /** Le profil B (et non plus A) couvre l'établissement A : la commande de la vitrine A est servie par B. */
    private function moveSiteAToOperatorB(): void
    {
        $a = $this->operatorA()
            ->setEtablissementPrincipal($this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_EDITEUR_NOM]))
            ->setEtablissementsRattaches([]);
        $this->operatorB()->addEtablissementRattache($this->siteA());
        $this->em()->flush();
        self::assertFalse($a->couvre($this->siteA()));
    }

    private function storeKey(ProfilExploitant $operator, string $key): void
    {
        $encryptor = new StripeKeyEncryptor((string) ($_ENV['STRIPE_KEYS_ENCRYPTION_KEY'] ?? getenv('STRIPE_KEYS_ENCRYPTION_KEY')));
        $existing = $this->em()->getRepository(StripeAccountConfig::class)->findOneBy(['businessProfile' => $operator]);
        $config = $existing ?? (new StripeAccountConfig())->setBusinessProfile($operator);
        $config->setSecretKeyEncrypted($encryptor->encrypt($key))->setKeyMode(StripeKeyMode::fromKey($key) ?? StripeKeyMode::Test)->setKeyUpdatedAt(new \DateTimeImmutable());
        $this->em()->persist($config);
        $this->em()->flush();
    }

    public function testEachOperatorPaysAndVerifiesWithItsOwnKeyAndNeverWithAnother(): void
    {
        $this->bothPrivate();
        $this->storeKey($this->operatorA(), $this->keyA);
        $this->storeKey($this->operatorB(), $this->keyB);
        FakeStripeServer::registerKey($this->keyA, 'acct_a');
        FakeStripeServer::registerKey($this->keyB, 'acct_b');
        FakeStripeServer::registerKey($this->keyGlobal, 'acct_global');

        // Commande servie par A.
        [$client, $panierId, $entete, $paiement] = $this->cartReadyToPay();
        self::assertSame('cb_psp', $paiement['moyen']);
        self::assertArrayNotHasKey('simulation', $paiement, 'a real provider, not the stub');
        FakeStripeServer::markPaid($paiement['referenceTransaction']);
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/retour-paiement', ['headers' => $entete, 'json' => ['referenceTransaction' => $paiement['referenceTransaction']]]);
        self::assertResponseIsSuccessful();
        self::assertSame(StatutVente::Validee, $this->sale($panierId)->getStatut());
        $keysOfA = FakeStripeServer::keysUsed();

        // Commande servie par B.
        $this->moveSiteAToOperatorB();
        [$client, $panierId, $entete, $paiement] = $this->cartReadyToPay();
        FakeStripeServer::markPaid($paiement['referenceTransaction']);
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/retour-paiement', ['headers' => $entete, 'json' => ['referenceTransaction' => $paiement['referenceTransaction']]]);
        self::assertResponseIsSuccessful();
        self::assertSame(StatutVente::Validee, $this->sale($panierId)->getStatut());
        $keysOfB = \array_slice(FakeStripeServer::keysUsed(), \count($keysOfA));

        // Témoins positifs : chaque exploitant a bien appelé Stripe, avec SA clé, deux fois (ouvrir, vérifier).
        self::assertSame([$this->keyA, $this->keyA], $keysOfA);
        self::assertSame([$this->keyB, $this->keyB], $keysOfB);
        self::assertNotEmpty(FakeStripeServer::keysUsed(), 'sinon l\'absence de la clé globale ne prouverait rien');
        self::assertNotContains($this->keyGlobal, FakeStripeServer::keysUsed(), 'the global key is only a fallback');
    }

    public function testAnOperatorWithoutAKeyFallsBackToTheGlobalKey(): void
    {
        $this->bothPrivate();
        FakeStripeServer::registerKey($this->keyGlobal, 'acct_global');

        [$client, $panierId, $entete, $paiement] = $this->cartReadyToPay();
        FakeStripeServer::markPaid($paiement['referenceTransaction']);
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/retour-paiement', ['headers' => $entete, 'json' => ['referenceTransaction' => $paiement['referenceTransaction']]]);

        self::assertResponseIsSuccessful();
        self::assertSame('cb_psp', $paiement['moyen']);
        self::assertSame([$this->keyGlobal, $this->keyGlobal], FakeStripeServer::keysUsed());
    }

    public function testAnOperatorWithItsOwnKeyGetsTheCardEvenWithoutAGlobalKey(): void
    {
        $this->setEnv('STRIPE_SECRET_KEY', null);
        $this->bothPrivate();
        $this->storeKey($this->operatorA(), $this->keyA);
        FakeStripeServer::registerKey($this->keyA, 'acct_a');

        [, , , $paiement] = $this->cartReadyToPay();

        self::assertSame('cb_psp', $paiement['moyen']);
        self::assertArrayNotHasKey('simulation', $paiement, 'Stripe, not the stub');
        self::assertSame([$this->keyA], FakeStripeServer::keysUsed());
    }

    public function testNoKeyAnywhereLeavesStripeUntouchedAndTheStubAnswersAsBefore(): void
    {
        $this->setEnv('STRIPE_SECRET_KEY', null);
        $this->bothPrivate();

        [, , , $paiement] = $this->cartReadyToPay();

        self::assertSame([], FakeStripeServer::calls(), 'Stripe is never called');
        self::assertArrayHasKey('simulation', $paiement, 'the demonstration stub answers, as before');
    }

    public function testAKeyReplacedByAnotherKeyOfTheSameAccountKeepsTheVerificationWorking(): void
    {
        $this->bothPrivate();
        $this->storeKey($this->operatorA(), $this->keyA);
        $rotated = 'sk_test_' . bin2hex(random_bytes(12));
        FakeStripeServer::registerKey($this->keyA, 'acct_a');
        FakeStripeServer::registerKey($rotated, 'acct_a');

        [$client, $panierId, $entete, $paiement] = $this->cartReadyToPay();
        $this->storeKey($this->operatorA(), $rotated);
        FakeStripeServer::markPaid($paiement['referenceTransaction']);
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/retour-paiement', ['headers' => $entete, 'json' => ['referenceTransaction' => $paiement['referenceTransaction']]]);

        self::assertResponseIsSuccessful();
        self::assertSame(StatutVente::Validee, $this->sale($panierId)->getStatut());
        self::assertSame([$this->keyA, $rotated], FakeStripeServer::keysUsed(), 'opened with the old key, verified with the new one');
    }

    public function testAKeyReplacedByAKeyOfAnotherAccountNeverConfirmsAndWarnsWithoutTheKey(): void
    {
        $this->bothPrivate();
        $this->storeKey($this->operatorA(), $this->keyA);
        $foreign = 'sk_test_' . bin2hex(random_bytes(12));
        FakeStripeServer::registerKey($this->keyA, 'acct_a');
        FakeStripeServer::registerKey($foreign, 'acct_other');

        [$client, $panierId, $entete, $paiement] = $this->cartReadyToPay();
        $this->storeKey($this->operatorA(), $foreign);
        FakeStripeServer::markPaid($paiement['referenceTransaction']);
        LogSpy::reset();
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/retour-paiement', ['headers' => $entete, 'json' => ['referenceTransaction' => $paiement['referenceTransaction']]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(StatutVente::EnCours, $this->sale($panierId)->getStatut(), 'never confirmed on a doubt');
        $dump = LogSpy::dump();
        self::assertStringContainsString('Session Stripe inconnue du compte courant', $dump, 'the operator can see it');
        self::assertStringContainsString((string) $this->operatorA()->getId(), $dump);
        self::assertStringContainsString('source=profile', $dump);
        foreach ([$this->keyA, $foreign, $this->keyGlobal] as $key) {
            self::assertStringNotContainsString($key, $dump);
        }
    }

    public function testNoJournalLineNorResponseEverQuotesTheKeyWhenStripeDoes(): void
    {
        $this->bothPrivate();
        $this->storeKey($this->operatorA(), $this->keyA);
        FakeStripeServer::registerKey($this->keyA, 'acct_a');
        // Témoin positif : le faux serveur cite bien la clé entière dans son message d'erreur.
        FakeStripeServer::rejectEverythingWith(401);

        LogSpy::reset();
        $entete = $this->cartUpToPayment();
        [$client, $panierId, $headers] = $entete;
        $response = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/payer', ['headers' => $headers]);

        self::assertResponseStatusCodeSame(502);
        self::assertSame([$this->keyA], FakeStripeServer::keysUsed(), 'Stripe received the key and answered with it quoted');
        $dump = LogSpy::dump();
        self::assertStringContainsString('Stripe a refusé l\'appel', $dump, 'the refusal is logged');
        self::assertStringContainsString('api_key_invalid', $dump, 'with its code');
        foreach ([$this->keyA, $this->keyGlobal] as $key) {
            self::assertStringNotContainsString($key, $dump);
            self::assertStringNotContainsString($key, $response->getContent(false));
        }
    }

    /** @return array{0: Client, 1: string, 2: array<string, string>} */
    private function cartUpToPayment(): array
    {
        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_SIMPLE_CODE]);
        [$client, $panierId, $jeton] = $this->ouvrirPanierInviteA();
        $entete = [PanierProprietaireGuard::HEADER => $jeton];

        $panier = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/lignes', ['headers' => $entete, 'json' => ['produit' => (string) $produit->getId(), 'quantite' => 1]])->toArray();
        $ligneId = (string) $panier['lignes'][0]['id'];
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/identifier', ['headers' => $entete, 'json' => ['mode' => 'invite', 'email' => 'stripe.compte@example.test']]);
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/consentement', ['headers' => $entete, 'json' => ['mentionVersion' => 'mention-test']]);
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/beneficiaires', ['headers' => $entete, 'json' => ['lignes' => [['ligneId' => $ligneId, 'beneficiaireSimple' => ['nom' => 'Stri', 'prenom' => 'Pe', 'dateNaissance' => '1990-01-01']]]]]);

        return [$client, $panierId, $entete];
    }

    /** @return array{0: Client, 1: string, 2: array<string, string>, 3: array<string, mixed>} */
    private function cartReadyToPay(): array
    {
        [$client, $panierId, $entete] = $this->cartUpToPayment();
        $paiement = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/payer', ['headers' => $entete])->toArray();
        self::assertResponseIsSuccessful();

        return [$client, $panierId, $entete, $paiement];
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
}
