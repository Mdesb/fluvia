<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Boutique\DataFixtures\BoutiqueFixtures;
use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Entity\SuiviCommandeEnLigne;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Compta\Entity\BordereauPayFiP;
use App\Compta\Enum\StatutPayFiP;
use App\Offre\Entity\Produit;
use App\Tests\Boutique\BoutiqueApiTestCase;
use App\Tests\Compta\Support\FakePayFipServer;
use App\Vente\Entity\Paiement;
use App\Vente\Entity\Vente;
use App\Vente\Enum\StatutVente;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * PAYFIP DE BOUT EN BOUT (#339) — le tunnel de la boutique, notre vrai client SOAP, et une doublure du
 * service PayFiP (`FakePayFipServer`) qui répond comme le guide DGFiP le décrit.
 *
 * Vitrine A = établissement en régie directe : c'est PayFiP qui sert dès que la configuration est
 * complète. Les témoins demandés par le ticket : un paiement validé confirme la commande ; un échec la
 * laisse impayée ; un retour falsifié est rejeté. S'y ajoutent la notification seule (acheteur qui ne
 * revient pas) et la double arrivée retour + notification (un seul encaissement).
 */
final class PayFipOnlinePaymentTest extends BoutiqueApiTestCase
{
    private const ENV = [
        'PAYFIP_NUMCLI' => '999123',
        'PAYFIP_ENTRY_MODE' => 'W',
        'PAYFIP_NOTIFICATION_URL' => 'https://boutique.example.test/api/boutique/paiement-en-ligne/notification/payfip',
        'BOUTIQUE_BASE_URL' => 'https://boutique.example.test',
    ];

    /**
     * Les TROIS sources d'une variable, rendues telles quelles. Dotenv peuple `$_ENV` et `$_SERVER` sans
     * `putenv()` : ne relire que `getenv()` faisait « effacer » BOUTIQUE_BASE_URL (posée par `.env`) en
     * sortant, et les classes de test suivantes tombaient en 500 « Environment variable not found ».
     *
     * @var array<string, array{0: string|false, 1: mixed, 2: mixed, 3: bool, 4: bool}>
     */
    private array $envAvant = [];

    protected function setUp(): void
    {
        foreach (self::ENV as $nom => $valeur) {
            $this->envAvant[$nom] = [getenv($nom), $_ENV[$nom] ?? null, $_SERVER[$nom] ?? null, \array_key_exists($nom, $_ENV), \array_key_exists($nom, $_SERVER)];
            putenv($nom . '=' . $valeur);
            $_ENV[$nom] = $_SERVER[$nom] = $valeur;
        }
        FakePayFipServer::reset();

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
        parent::tearDown();
    }

    public function testPayerOuvreUneOperationPayFipConformeAuGuide(): void
    {
        [, $panierId, , $paiement] = $this->panierPretAPayer();

        self::assertSame('payfip', $paiement['moyen']);
        self::assertArrayNotHasKey('simulation', $paiement, 'un vrai prestataire ne remet aucun reçu à l\'acheteur');
        $idOp = $paiement['referenceTransaction'];
        self::assertSame('https://www.payfip.gouv.fr/tpa/paiementws.web?idop=' . rawurlencode($idOp), $paiement['urlRedirection']);

        $envoye = FakePayFipServer::operations()[$idOp] ?? null;
        self::assertIsArray($envoye, 'creerPaiementSecurise a bien été appelé');
        $vente = $this->vente($panierId);
        self::assertSame('999123', $envoye['numcli']);
        self::assertSame('W', $envoye['saisie']);
        self::assertSame((string) $paiement['montantCentimes'], $envoye['montant']);
        self::assertSame(strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $vente->getNumero())), $envoye['refdet'], 'REFDET = notre numéro de vente, lisible dans la remise du régisseur');
        self::assertMatchesRegularExpression('/^[A-Za-z0-9]{6,30}$/', $envoye['refdet']);
        self::assertSame('payfip.retour@example.test', $envoye['mel']);
        self::assertSame(self::ENV['PAYFIP_NOTIFICATION_URL'], $envoye['urlnotif']);
        self::assertSame('https://boutique.example.test/b/piscine-a/retour-paiement', $envoye['urlredirect']);
        self::assertSame(date('Y'), $envoye['exer']);

        $bordereau = $this->bordereau($idOp);
        self::assertSame(StatutPayFiP::EnAttente, $bordereau->getStatutRetour());
        self::assertSame($envoye['refdet'], $bordereau->getDebtReference());
        self::assertSame($paiement['montantCentimes'], $bordereau->getAmountCents());
        self::assertSame((string) $vente->getId(), (string) $bordereau->getVenteOrigine());
    }

    /** Témoin positif : PayFiP dit « payé » → la commande est validée, encaissée une fois, rapprochée. */
    public function testUnPaiementValideParPayFipConfirmeLaCommande(): void
    {
        [$client, $panierId, $entete, $paiement] = $this->panierPretAPayer();
        $idOp = $paiement['referenceTransaction'];
        FakePayFipServer::decide($idOp, 'P');

        $reponse = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/retour-paiement', [
            'headers' => $entete,
            'json' => ['referenceTransaction' => $idOp],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('confirme', $reponse->toArray()['statut']);
        $vente = $this->vente($panierId);
        self::assertSame(StatutVente::Validee, $vente->getStatut());
        $paiements = $vente->getPaiements()->toArray();
        self::assertCount(1, $paiements);
        self::assertInstanceOf(Paiement::class, $paiements[0]);
        self::assertSame('payfip', $paiements[0]->getMoyenCode());
        self::assertSame($idOp, $paiements[0]->getRefTPE());

        $bordereau = $this->bordereau($idOp);
        self::assertSame(StatutPayFiP::Ok, $bordereau->getStatutRetour());
        self::assertTrue($bordereau->isVenteRapprochee());
        self::assertSame('P', $bordereau->getTransactionResult());
        self::assertSame('123456', $bordereau->getAuthorizationNumber());
        self::assertSame('2026-11-05 14:32', $bordereau->getTransactedAt()?->format('Y-m-d H:i'));
    }

    /** L'acheteur ferme son navigateur : la notification de PayFiP suffit, et le retour tardif ne réencaisse pas. */
    public function testLaNotificationSeuleConfirmeEtLeRetourNeReencaissePas(): void
    {
        [$client, $panierId, $entete, $paiement] = $this->panierPretAPayer();
        $idOp = $paiement['referenceTransaction'];
        FakePayFipServer::decide($idOp, 'P');

        $notification = $client->request('GET', '/api/boutique/paiement-en-ligne/notification/payfip?idop=' . rawurlencode($idOp));
        self::assertResponseIsSuccessful();
        self::assertSame('confirme', $notification->toArray()['statut']);
        self::assertSame(StatutVente::Validee, $this->vente($panierId)->getStatut());

        $retour = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/retour-paiement', [
            'headers' => $entete,
            'json' => ['referenceTransaction' => $idOp],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame('confirme', $retour->toArray()['statut']);
        self::assertCount(1, $this->vente($panierId)->getPaiements(), 'un seul règlement, quelle que soit la porte');
    }

    /** Témoin négatif du ticket : un refus de la banque laisse la commande impayée, et réessayable. */
    public function testUnRefusLaisseLaCommandeImpayee(): void
    {
        [$client, $panierId, $entete, $paiement] = $this->panierPretAPayer();
        $idOp = $paiement['referenceTransaction'];
        FakePayFipServer::decide($idOp, 'R');

        $reponse = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/retour-paiement', [
            'headers' => $entete,
            'json' => ['referenceTransaction' => $idOp],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(['statut' => 'echec', 'peutReessayer' => true, 'panier' => $panierId], $reponse->toArray());
        $this->assertImpayee($panierId);
        self::assertSame(StatutPayFiP::Echec, $this->bordereau($idOp)->getStatutRetour());
        self::assertFalse($this->bordereau($idOp)->isVenteRapprochee());
    }

    public function testUnAbandonLaisseLaCommandeImpayee(): void
    {
        [$client, $panierId, $entete, $paiement] = $this->panierPretAPayer();
        FakePayFipServer::decide($paiement['referenceTransaction'], 'A');

        $reponse = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/retour-paiement', [
            'headers' => $entete,
            'json' => ['referenceTransaction' => $paiement['referenceTransaction']],
        ]);

        self::assertSame('echec', $reponse->toArray()['statut']);
        $this->assertImpayee($panierId);
    }

    /** P5 : PayFiP ne connaît pas encore l'issue. Ce n'est pas un échec : on ne propose pas de repayer. */
    public function testUneIssueEncoreInconnueLaisseEnAttenteSansProposerDeRepayer(): void
    {
        [$client, $panierId, $entete, $paiement] = $this->panierPretAPayer();

        $reponse = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/retour-paiement', [
            'headers' => $entete,
            'json' => ['referenceTransaction' => $paiement['referenceTransaction']],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('en_attente', $reponse->toArray()['statut']);
        self::assertFalse($reponse->toArray()['peutReessayer']);
        $this->assertImpayee($panierId);
    }

    /** Retour falsifié n°1 : l'acheteur affirme « accepté » et joint un reçu. Seul PayFiP décide — ici, rien encore. */
    public function testUnStatutEtUnRecuForgesNeDecidentRien(): void
    {
        [$client, $panierId, $entete, $paiement] = $this->panierPretAPayer();

        $reponse = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/retour-paiement', [
            'headers' => $entete,
            'json' => ['referenceTransaction' => $paiement['referenceTransaction'], 'statut' => 'accepte', 'resultrans' => 'P', 'recu' => str_repeat('a', 64), 'montantCentimes' => 1],
        ]);

        self::assertSame('en_attente', $reponse->toArray()['statut']);
        $this->assertImpayee($panierId);
    }

    /** Retour falsifié n°2 : une autre opération, payée, présentée pour cette commande. */
    public function testUneOperationQuiNEstPasCelleInitieeEstRefusee(): void
    {
        [$client, $panierId, $entete, $paiement] = $this->panierPretAPayer();
        FakePayFipServer::decide($paiement['referenceTransaction'], 'R');

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/retour-paiement', [
            'headers' => $entete,
            'json' => ['referenceTransaction' => '00000000-0000-0000-0000-000000000000'],
        ]);

        self::assertResponseStatusCodeSame(422);
        $this->assertImpayee($panierId);
    }

    /**
     * Retour falsifié n°3 : PayFiP répond « payé », mais pour un autre montant, un autre client, une autre
     * dette, ou en mode test pour une commande initiée en réel. Aucune de ces réponses n'atteste NOTRE
     * opération.
     *
     * @param array<string, string> $ecart
     */
    #[DataProvider('reponsesQuiNAttestentPasNotreOperation')]
    public function testUneReponsePayeeQuiNeCorrespondPasEstRejetee(array $ecart): void
    {
        [$client, $panierId, $entete, $paiement] = $this->panierPretAPayer();
        FakePayFipServer::decide($paiement['referenceTransaction'], 'P', $ecart);

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/retour-paiement', [
            'headers' => $entete,
            'json' => ['referenceTransaction' => $paiement['referenceTransaction']],
        ]);

        self::assertResponseStatusCodeSame(422);
        $this->assertImpayee($panierId);
    }

    /** @return iterable<string, array{0: array<string, string>}> */
    public static function reponsesQuiNAttestentPasNotreOperation(): iterable
    {
        yield 'montant' => [['montant' => '1']];
        yield 'numéro de client' => [['numcli' => '000001']];
        yield 'référence de dette' => [['refdet' => 'AUTRECOMMANDE01']];
        yield 'paiement de test' => [['saisie' => 'T']];
        yield 'autre opération' => [['idOp' => '11111111-1111-1111-1111-111111111111']];
    }

    /** Retour falsifié n°4 : une notification pour une opération inventée ne touche à rien. */
    public function testUneNotificationForgeeNeToucheARien(): void
    {
        [$client, $panierId] = $this->panierPretAPayer();

        $client->request('POST', '/api/boutique/paiement-en-ligne/notification/payfip', ['extra' => ['parameters' => ['idop' => 'ffffffff-ffff-ffff-ffff-ffffffffffff']]]);
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/api/boutique/paiement-en-ligne/notification/payfip?idop=ffffffff-ffff-ffff-ffff-ffffffffffff');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/api/boutique/paiement-en-ligne/notification/payfip?idop=' . rawurlencode('<script>'));
        self::assertResponseStatusCodeSame(404);
        $this->assertImpayee($panierId);
    }

    /** Retour falsifié n°5 : la VRAIE référence, notifiée avant que l'acheteur ait payé. On demande : PayFiP dit P5. */
    public function testUneNotificationAvantPaiementNeConfirmeRien(): void
    {
        [$client, $panierId, , $paiement] = $this->panierPretAPayer();

        $reponse = $client->request('GET', '/api/boutique/paiement-en-ligne/notification/payfip?idop=' . rawurlencode($paiement['referenceTransaction']));

        self::assertResponseIsSuccessful();
        self::assertSame('en_attente', $reponse->toArray()['statut']);
        $this->assertImpayee($panierId);
    }

    /** L'acheteur revient sur « payer » après avoir payé dans un autre onglet : on règle, on n'ouvre pas une seconde opération. */
    public function testRepasserParPayerApresAvoirPayeReglePlutotQueDeRouvrir(): void
    {
        [$client, $panierId, $entete, $paiement] = $this->panierPretAPayer();
        FakePayFipServer::decide($paiement['referenceTransaction'], 'P');

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/payer', ['headers' => $entete]);

        self::assertResponseStatusCodeSame(409);
        self::assertCount(1, FakePayFipServer::operations(), 'aucune seconde opération ouverte');
        self::assertSame(StatutVente::Validee, $this->vente($panierId)->getStatut());
        self::assertCount(1, $this->vente($panierId)->getPaiements());
    }

    /** PayFiP refuse l'ouverture (paramètre rejeté) : l'acheteur reçoit une erreur claire, rien n'est ouvert. */
    public function testUnRefusDOuvertureDePayFipEstUneErreurClaire(): void
    {
        FakePayFipServer::failNextCreation('R3');

        $client = $this->panierPretAPayer(payer: false);
        $client[0]->request('POST', '/api/boutique/paniers/' . $client[1] . '/payer', ['headers' => $client[2]]);

        self::assertResponseStatusCodeSame(502);
        self::assertSame([], FakePayFipServer::operations());
    }

    /** @return array{0: Client, 1: string, 2: array<string, string>, 3: array<string, mixed>} */
    private function panierPretAPayer(bool $payer = true): array
    {
        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_SIMPLE_CODE]);
        [$client, $panierId, $jeton] = $this->ouvrirPanierInviteA();
        $entete = [PanierProprietaireGuard::HEADER => $jeton];

        $panier = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/lignes', ['headers' => $entete, 'json' => ['produit' => (string) $produit->getId(), 'quantite' => 1]])->toArray();
        $ligneId = (string) $panier['lignes'][0]['id'];
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/identifier', ['headers' => $entete, 'json' => ['mode' => 'invite', 'email' => 'payfip.retour@example.test']]);
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/consentement', ['headers' => $entete, 'json' => ['mentionVersion' => 'mention-test']]);
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/beneficiaires', ['headers' => $entete, 'json' => ['lignes' => [['ligneId' => $ligneId, 'beneficiaireSimple' => ['nom' => 'Pay', 'prenom' => 'Fip', 'dateNaissance' => '1990-01-01']]]]]);

        if (!$payer) {
            return [$client, $panierId, $entete, []];
        }

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

    private function bordereau(string $idOp): BordereauPayFiP
    {
        $this->em()->clear();
        $bordereau = $this->em()->getRepository(BordereauPayFiP::class)->findOneBy(['referenceTransaction' => $idOp]);
        self::assertInstanceOf(BordereauPayFiP::class, $bordereau);

        return $bordereau;
    }

    private function assertImpayee(string $panierId): void
    {
        $vente = $this->vente($panierId);
        self::assertSame(StatutVente::EnCours, $vente->getStatut(), 'la vente ne doit pas avoir été validée');
        self::assertCount(0, $vente->getPaiements(), 'aucun règlement ne doit avoir été enregistré');
    }
}
