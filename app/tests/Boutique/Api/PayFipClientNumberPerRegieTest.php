<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Boutique\DataFixtures\BoutiqueFixtures;
use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Entity\SuiviCommandeEnLigne;
use App\Boutique\Paiement\OnlinePaymentContext;
use App\Boutique\Paiement\PayFipOnlinePaymentAdapter;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Compta\Entity\BordereauPayFiP;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Enum\StatutPayFiP;
use App\Compta\Enum\TypeExploitant;
use App\DataFixtures\SocleFixtures;
use App\Offre\Entity\Produit;
use App\Organisation\Entity\Etablissement;
use App\Tests\Boutique\BoutiqueApiTestCase;
use App\Tests\Compta\Support\FakePayFipServer;
use App\Vente\Entity\Vente;
use App\Vente\Enum\StatutVente;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * UN NUMÉRO CLIENT PAYFIP PAR RÉGIE, DE BOUT EN BOUT (D2-B de #365) — le tunnel de la boutique, notre vrai client
 * SOAP et la doublure `FakePayFipServer`.
 *
 * G-2 : payer envoie le NUMCLI du profil de la commande, jamais celui d'une autre régie ni la variable globale.
 *
 * La commande est celle de la vitrine A (la seule qui ait un catalogue). Une autre régie la sert quand on lui
 * « rattache » l'établissement A et qu'on retire A au profil d'origine : le résolveur choisit alors le profil de
 * la commande, donc son numéro (même technique que `StripeAccountPerProfileTest`).
 */
final class PayFipClientNumberPerRegieTest extends BoutiqueApiTestCase
{
    private const GLOBAL_NUMBER = '777777';
    private const NUMBER_A = '111111';
    private const NUMBER_B = '222222';

    /** La variable globale d'avant D2-B, posée à une TROISIÈME valeur pour prouver qu'elle n'est pas lue. */
    private const LEGACY_VARIABLE = 'PAYFIP_NUMCLI';

    private const ENV = [
        self::LEGACY_VARIABLE => self::GLOBAL_NUMBER,
        'PAYFIP_ENTRY_MODE' => 'W',
        'PAYFIP_NOTIFICATION_URL' => 'https://boutique.example.test/api/boutique/paiement-en-ligne/notification/payfip',
        'BOUTIQUE_BASE_URL' => 'https://boutique.example.test',
    ];

    /** @var array<string, array{0: string|false, 1: mixed, 2: mixed, 3: bool, 4: bool}> */
    private array $envBefore = [];

    protected function setUp(): void
    {
        foreach (self::ENV as $name => $value) {
            $this->envBefore[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null, \array_key_exists($name, $_ENV), \array_key_exists($name, $_SERVER)];
            putenv($name . '=' . $value);
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        FakePayFipServer::reset();

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

    /** Deux régies directes, chacune avec son numéro. */
    private function twoRegies(): void
    {
        $this->setPayFipClientNumber(SocleFixtures::ETAB_A_NOM, self::NUMBER_A);
        $this->operatorB()->setType(TypeExploitant::RegieDirecte);
        $this->setPayFipClientNumber(SocleFixtures::ETAB_B_NOM, self::NUMBER_B);
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

    private function sentBy(string $idOp): string
    {
        $sent = FakePayFipServer::operations()[$idOp] ?? null;
        self::assertIsArray($sent, 'creerPaiementSecurise a bien été appelé');

        return $sent['numcli'];
    }

    private function bordereau(string $idOp): BordereauPayFiP
    {
        $this->em()->clear();
        $bordereau = $this->em()->getRepository(BordereauPayFiP::class)->findOneBy(['referenceTransaction' => $idOp]);
        self::assertInstanceOf(BordereauPayFiP::class, $bordereau);

        return $bordereau;
    }

    public function testEachRegieSendsItsOwnNumberAndNeverAnotherNorTheGlobalVariable(): void
    {
        $this->twoRegies();

        [, , , $paiementA] = $this->cartReadyToPay();
        self::assertSame('payfip', $paiementA['moyen']);
        $this->moveSiteAToOperatorB();
        [, , , $paiementB] = $this->cartReadyToPay();
        self::assertSame('payfip', $paiementB['moyen']);

        // Témoins positifs : les deux opérations existent, chacune avec le numéro de SA régie.
        self::assertCount(2, FakePayFipServer::operations());
        self::assertSame(self::NUMBER_A, $this->sentBy($paiementA['referenceTransaction']));
        self::assertSame(self::NUMBER_B, $this->sentBy($paiementB['referenceTransaction']));
        $sent = array_column(FakePayFipServer::operations(), 'numcli');
        self::assertNotContains(self::GLOBAL_NUMBER, $sent, 'la variable globale n\'est pas envoyée');

        // Le numéro envoyé est figé sur le bordereau de l'opération (étape 4).
        self::assertSame(self::NUMBER_A, $this->bordereau($paiementA['referenceTransaction'])->getClientNumber());
        self::assertSame(self::NUMBER_B, $this->bordereau($paiementB['referenceTransaction'])->getClientNumber());
    }

    /**
     * Course : le numéro est retiré entre le choix de l'adaptateur et l'appel. L'adaptateur refuse (422) AVANT tout
     * appel réseau, avec un témoin positif : la même initiation passe quand le numéro est là.
     */
    public function testAProfileWithoutAWellFormedNumberIsRefusedBeforeAnyNetworkCall(): void
    {
        $adapter = static::getContainer()->get(PayFipOnlinePaymentAdapter::class);
        $profile = $this->setPayFipClientNumber(SocleFixtures::ETAB_A_NOM, self::NUMBER_A);
        $context = new OnlinePaymentContext('CMD-2026-000042', 'payfip.retour@example.test', $profile);

        $initiation = $adapter->initierPaiement(Uuid::v4(), 5000, 'https://boutique.example.test/retour', $context);
        self::assertCount(1, FakePayFipServer::operations(), 'témoin : avec un numéro, l\'opération s\'ouvre');
        self::assertNotSame('', $initiation->referenceTransaction);

        $calls = \count(FakePayFipServer::calls());
        foreach ([null, '12345'] as $bad) {
            $profile->setPayfipClientNumber($bad);
            try {
                $adapter->initierPaiement(Uuid::v4(), 5000, 'https://boutique.example.test/retour', $context);
                self::fail('une initiation sans numéro conforme doit être refusée');
            } catch (UnprocessableEntityHttpException) {
                self::assertCount(1, FakePayFipServer::operations());
                self::assertCount($calls, FakePayFipServer::calls(), 'aucun appel réseau');
            }
        }
    }

    /** G-3 : une régie sans numéro n'a pas PayFiP réel — le bouchon de démonstration répond, le faux serveur n'est pas appelé. */
    public function testARegieWithoutANumberFallsBackToTheStubAndNeverCallsPayFip(): void
    {
        $this->setPayFipClientNumber(SocleFixtures::ETAB_A_NOM, null);

        [, , , $payment] = $this->cartReadyToPay();

        self::assertSame([], FakePayFipServer::operations(), 'PayFiP n\'est pas appelé');
        self::assertArrayHasKey('simulation', $payment, 'le bouchon de démonstration répond');

        // Témoin positif : avec un numéro, la même commande ouvre une opération PayFiP.
        $this->setPayFipClientNumber(SocleFixtures::ETAB_A_NOM, self::NUMBER_A);
        [, , , $payment] = $this->cartReadyToPay();
        self::assertCount(1, FakePayFipServer::operations());
        self::assertArrayNotHasKey('simulation', $payment);
    }

    // ── Étape 6 : la vérification compare au NUMCLI FIGÉ à l'initiation ───────────────────────────

    public function testTheReturnOfARegieIsComparedToItsOwnNumberAndNotToAnotherRegiesNumber(): void
    {
        $this->twoRegies();

        // Commande de A, PayFiP répond avec le numéro de A (celui qu'on a envoyé) : confirmée (témoin positif).
        [$client, $cartId, $headers, $payment] = $this->cartReadyToPay();
        FakePayFipServer::decide($payment['referenceTransaction'], 'P');
        $client->request('POST', '/api/boutique/paniers/' . $cartId . '/retour-paiement', ['headers' => $headers, 'json' => ['referenceTransaction' => $payment['referenceTransaction']]]);
        self::assertResponseIsSuccessful();
        self::assertSame(StatutVente::Validee, $this->sale($cartId)->getStatut());

        // Autre commande de A, mais PayFiP répond avec le numéro de B : refusée, jamais confirmée.
        [$client, $cartId, $headers, $payment] = $this->cartReadyToPay();
        self::assertSame(self::NUMBER_A, $this->sentBy($payment['referenceTransaction']));
        FakePayFipServer::decide($payment['referenceTransaction'], 'P', ['numcli' => self::NUMBER_B]);
        $client->request('POST', '/api/boutique/paniers/' . $cartId . '/retour-paiement', ['headers' => $headers, 'json' => ['referenceTransaction' => $payment['referenceTransaction']]]);
        self::assertResponseStatusCodeSame(422);
        $this->assertUnpaid($cartId);
    }

    public function testChangingTheNumberMidWayDoesNotBreakAnOperationAlreadyInitiatedAndANewOneUsesTheNewNumber(): void
    {
        $this->setPayFipClientNumber(SocleFixtures::ETAB_A_NOM, self::NUMBER_A);
        [$client, $cartId, $headers, $payment] = $this->cartReadyToPay();
        $idOp = $payment['referenceTransaction'];
        self::assertSame(self::NUMBER_A, $this->sentBy($idOp));

        // Le numéro de A passe à 222222 par l'opération d'écriture (le vrai chemin).
        [$admin, $adminHeaders] = $this->adminSurA();
        $profileId = (string) $this->operatorA()->getId();
        $admin->request('PATCH', '/api/profil_exploitants/' . $profileId . '/payfip_client_number', [
            'headers' => $adminHeaders['headers'] + ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['payfipClientNumber' => self::NUMBER_B],
        ] + $adminHeaders);
        self::assertResponseIsSuccessful();
        $this->em()->clear();
        self::assertSame(self::NUMBER_B, $this->operatorA()->getPayfipClientNumber());

        // L'opération déjà initiée est encore attestée : PayFiP répond le numéro d'origine.
        FakePayFipServer::decide($idOp, 'P');
        self::assertSame(self::NUMBER_A, FakePayFipServer::operations()[$idOp]['numcli']);
        $client->request('POST', '/api/boutique/paniers/' . $cartId . '/retour-paiement', ['headers' => $headers, 'json' => ['referenceTransaction' => $idOp]]);
        self::assertResponseIsSuccessful();
        self::assertSame(StatutVente::Validee, $this->sale($cartId)->getStatut());

        // Une NOUVELLE initiation part avec le nouveau numéro.
        [, , , $next] = $this->cartReadyToPay();
        self::assertSame(self::NUMBER_B, $this->sentBy($next['referenceTransaction']));
    }

    public function testTheNotificationAloneFollowsTheSameRule(): void
    {
        $this->setPayFipClientNumber(SocleFixtures::ETAB_A_NOM, self::NUMBER_A);

        // Numéro étranger dans la réponse : la notification n'atteste rien.
        [$client, $cartId, , $payment] = $this->cartReadyToPay();
        FakePayFipServer::decide($payment['referenceTransaction'], 'P', ['numcli' => self::NUMBER_B]);
        $client->request('GET', '/api/boutique/paiement-en-ligne/notification/payfip?idop=' . rawurlencode($payment['referenceTransaction']));
        self::assertResponseStatusCodeSame(422, 'la notification ne confirme pas');
        $this->assertUnpaid($cartId);

        // Témoin positif : la même notification, avec le numéro figé, confirme.
        [$client, $cartId, , $payment] = $this->cartReadyToPay();
        FakePayFipServer::decide($payment['referenceTransaction'], 'P');
        $client->request('GET', '/api/boutique/paiement-en-ligne/notification/payfip?idop=' . rawurlencode($payment['referenceTransaction']));
        self::assertResponseIsSuccessful();
        self::assertSame(StatutVente::Validee, $this->sale($cartId)->getStatut());
    }

    /**
     * I-1 : le numéro est EFFACÉ (par le vrai PATCH) entre l'initiation et le retour. L'opération déjà initiée se
     * vérifie quand même par PayFiP, contre le numéro figé : le retour ET la notification confirment, au lieu de
     * tomber sur le bouchon. Témoin positif : sans effacement, le même chemin confirme.
     */
    public function testAnOperationInitiatedBeforeTheNumberWasErasedIsStillConfirmedByReturnAndNotification(): void
    {
        foreach ([false, true] as $erase) {
            foreach (['return', 'notification'] as $door) {
                $this->setPayFipClientNumber(SocleFixtures::ETAB_A_NOM, self::NUMBER_A);
                [$client, $cartId, $headers, $payment] = $this->cartReadyToPay();
                $idOp = $payment['referenceTransaction'];
                self::assertSame(self::NUMBER_A, $this->sentBy($idOp));

                if ($erase) {
                    [$admin, $adminHeaders] = $this->adminSurA();
                    $admin->request('PATCH', '/api/profil_exploitants/' . (string) $this->operatorA()->getId() . '/payfip_client_number', [
                        'headers' => $adminHeaders['headers'] + ['Content-Type' => 'application/merge-patch+json'],
                        'json' => ['payfipClientNumber' => null],
                    ] + $adminHeaders);
                    self::assertResponseIsSuccessful();
                    $this->em()->clear();
                    self::assertNull($this->operatorA()->getPayfipClientNumber(), 'le numéro est bien effacé');
                }

                $callsBefore = \count(FakePayFipServer::calls());
                FakePayFipServer::decide($idOp, 'P');
                if ($door === 'return') {
                    $client->request('POST', '/api/boutique/paniers/' . $cartId . '/retour-paiement', ['headers' => $headers, 'json' => ['referenceTransaction' => $idOp]]);
                } else {
                    $client->request('GET', '/api/boutique/paiement-en-ligne/notification/payfip?idop=' . rawurlencode($idOp));
                }
                self::assertResponseIsSuccessful();
                self::assertSame(StatutVente::Validee, $this->sale($cartId)->getStatut(), sprintf('%s, effacement=%s', $door, var_export($erase, true)));
                self::assertGreaterThan($callsBefore, \count(FakePayFipServer::calls()), 'PayFiP a été interrogé (pas le bouchon)');
                self::assertSame(self::NUMBER_A, $this->bordereau($idOp)->getClientNumber(), 'le numéro figé est conservé');
            }
        }
    }

    /** Un bordereau d'une AUTRE vente (créable à référence libre par `PayFipRetourProcessor`) ne détourne rien. */
    private function foreignBordereau(string $reference): void
    {
        $foreign = (new BordereauPayFiP())->setVenteOrigine(Uuid::v4())->setReferenceTransaction($reference)->setStatutRetour(StatutPayFiP::EnAttente);
        $foreign->recordInitiation('FOREIGN', 100, 'W', '999999');
        $this->em()->persist($foreign);
        $this->em()->flush();
    }

    public function testAForeignBordereauCarryingTheReferenceOfAStubOrderDoesNotHijackItsVerification(): void
    {
        $this->setPayFipClientNumber(SocleFixtures::ETAB_A_NOM, null);
        [$client, $cartId, $headers, $payment] = $this->cartReadyToPay();
        self::assertArrayHasKey('simulation', $payment, 'témoin : la commande est celle du bouchon');
        $this->foreignBordereau($payment['referenceTransaction']);

        $client->request('POST', '/api/boutique/paniers/' . $cartId . '/retour-paiement', ['headers' => $headers, 'json' => ['referenceTransaction' => $payment['referenceTransaction'], 'recu' => $payment['simulation']['accepte']]]);
        self::assertResponseIsSuccessful();
        self::assertSame(StatutVente::Validee, $this->sale($cartId)->getStatut());
        self::assertSame([], FakePayFipServer::operations(), 'PayFiP n\'a jamais été interrogé');
    }

    public function testALegitimateBordereauStillWorksNextToAForeignOneWithTheSameReference(): void
    {
        $this->setPayFipClientNumber(SocleFixtures::ETAB_A_NOM, self::NUMBER_A);
        [$client, $cartId, $headers, $payment] = $this->cartReadyToPay();
        $idOp = $payment['referenceTransaction'];
        $this->foreignBordereau($idOp);
        FakePayFipServer::decide($idOp, 'P');

        $client->request('POST', '/api/boutique/paniers/' . $cartId . '/retour-paiement', ['headers' => $headers, 'json' => ['referenceTransaction' => $idOp]]);
        self::assertResponseIsSuccessful();
        self::assertSame(StatutVente::Validee, $this->sale($cartId)->getStatut());
    }

    public function testABordereauWithoutAFrozenNumberAttestsNothing(): void
    {
        $this->setPayFipClientNumber(SocleFixtures::ETAB_A_NOM, self::NUMBER_A);
        [$client, $cartId, $headers, $payment] = $this->cartReadyToPay();
        $idOp = $payment['referenceTransaction'];
        FakePayFipServer::decide($idOp, 'P');
        $this->em()->getConnection()->executeStatement('UPDATE compta_bordereau_payfip SET client_number = NULL WHERE reference_transaction = ?', [$idOp]);

        // Fermeture à l'échec : PayFiP répond le bon numéro, mais rien n'est figé : refusé.
        $client->request('POST', '/api/boutique/paniers/' . $cartId . '/retour-paiement', ['headers' => $headers, 'json' => ['referenceTransaction' => $idOp]]);
        self::assertResponseStatusCodeSame(422);
        $this->assertUnpaid($cartId);

        // Témoin positif : une fois le numéro figé rétabli, la même réponse confirme.
        $this->em()->getConnection()->executeStatement('UPDATE compta_bordereau_payfip SET client_number = ? WHERE reference_transaction = ?', [self::NUMBER_A, $idOp]);
        $client->request('POST', '/api/boutique/paniers/' . $cartId . '/retour-paiement', ['headers' => $headers, 'json' => ['referenceTransaction' => $idOp]]);
        self::assertResponseIsSuccessful();
        self::assertSame(StatutVente::Validee, $this->sale($cartId)->getStatut());
    }

    private function sale(string $cartId): Vente
    {
        $this->em()->clear();
        $cart = $this->em()->getRepository(PanierEnLigne::class)->find($cartId);
        self::assertInstanceOf(PanierEnLigne::class, $cart);
        $sale = $this->em()->getRepository(SuiviCommandeEnLigne::class)->findOneBy(['panierOrigine' => $cart])?->getVente();
        self::assertInstanceOf(Vente::class, $sale);

        return $sale;
    }

    private function assertUnpaid(string $cartId): void
    {
        $sale = $this->sale($cartId);
        self::assertSame(StatutVente::EnCours, $sale->getStatut(), 'la vente ne doit pas avoir été validée');
        self::assertCount(0, $sale->getPaiements(), 'aucun règlement ne doit avoir été enregistré');
    }

    /** @return array{0: Client, 1: string, 2: array<string, string>, 3: array<string, mixed>} */
    private function cartReadyToPay(): array
    {
        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_SIMPLE_CODE]);
        [$client, $panierId, $jeton] = $this->ouvrirPanierInviteA();
        $entete = [PanierProprietaireGuard::HEADER => $jeton];

        $panier = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/lignes', ['headers' => $entete, 'json' => ['produit' => (string) $produit->getId(), 'quantite' => 1]])->toArray();
        $ligneId = (string) $panier['lignes'][0]['id'];
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/identifier', ['headers' => $entete, 'json' => ['mode' => 'invite', 'email' => 'payfip.retour@example.test']]);
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/consentement', ['headers' => $entete, 'json' => ['mentionVersion' => 'mention-test']]);
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/beneficiaires', ['headers' => $entete, 'json' => ['lignes' => [['ligneId' => $ligneId, 'beneficiaireSimple' => ['nom' => 'Pay', 'prenom' => 'Fip', 'dateNaissance' => '1990-01-01']]]]]);

        $paiement = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/payer', ['headers' => $entete])->toArray();
        self::assertResponseIsSuccessful();

        return [$client, $panierId, $entete, $paiement];
    }
}
