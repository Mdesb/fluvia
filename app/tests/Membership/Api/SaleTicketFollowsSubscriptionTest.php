<?php

declare(strict_types=1);

namespace App\Tests\Membership\Api;

use App\Acces\DataFixtures\AccesFixtures;
use App\Acces\Entity\Equipement;
use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\ProductAccessZone;
use App\Compta\DataFixtures\ComptaFixtures;
use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Client;
use App\DataFixtures\SocleFixtures;
use App\Membership\Entity\EcheanceSepa;
use App\Membership\Entity\Membership;
use App\Membership\Entity\Resiliation;
use App\Membership\Entity\StatutAccesFitness;
use App\Membership\Enum\MembershipStatus;
use App\Membership\Enum\StatutEcheanceSepa;
use App\Membership\Enum\StatutResiliation;
use App\Membership\Enum\TermRenewalMode;
use App\Membership\Recouvrement\AbonnementFitnessRedevablePort;
use App\Membership\Service\DemanderResiliationHandler;
use App\Membership\Service\SubscriptionTermHandler;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Produit;
use App\Organisation\Entity\Etablissement;
use App\Recouvrement\DataFixtures\RecouvrementFixtures;
use App\Recouvrement\Service\PropagationAccesHandler;
use App\Sepa\DataFixtures\SepaFixtures;
use App\Sepa\Enum\StatutMandatSepa;
use App\Sport\DataFixtures\SportFixtures;
use App\Tests\Acces\AccesApiTestCase;
use App\Tests\Acces\SnapshotDeltaTrait;
use App\Vente\Entity\BilletSupport;
use App\Vente\Entity\Vente;
use App\Vente\Enum\StatutVente;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * LE BILLET D'UNE FORMULE VENDUE EN CAISSE EST LIÉ À L'ABONNEMENT (décision de Maxime du 07/10).
 *
 * Vendre une formule nominative à un client émet le billet de la vente, seul accès remis au client.
 * Mesuré le 07/10 sur main : son droit est de source `billet`, sans fin de validité, et rien ne le
 * coupe. Il ouvrait encore après la résiliation, l'impayé ou le terme, quand le QR de l'abonnement,
 * que personne n'avait reçu, était coupé.
 *
 * La question est posée à la borne (`/terminal/passages`, et le snapshot des bornes hors ligne),
 * jamais à une colonne. L'anti-passback est coupé sur la borne de démonstration : on veut le verdict
 * du droit, pas celui du délai entre deux passages du même billet.
 */
final class SaleTicketFollowsSubscriptionTest extends AccesApiTestCase
{
    use SnapshotDeltaTrait;

    /** La dernière vente passée par `sell()`. */
    private string $saleId = '';

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();
        foreach ([ComptaFixtures::class, CrmFixtures::class, SepaFixtures::class, RecouvrementFixtures::class, SportFixtures::class] as $classe) {
            $container->get($classe)->load($em);
        }

        // Le Gold et l'entrée ouvrent l'espace de la borne de démonstration (D87).
        $espace = $this->entite(EspaceAcces::class, ['libelle' => AccesFixtures::ESPACE_LIBELLE]);
        $etablissement = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);
        foreach ([OffreFixtures::PRODUIT_GOLD, OffreFixtures::PRODUIT_ENTREE] as $libelle) {
            $produit = $this->entite(Produit::class, ['libelleRecherche' => $libelle]);
            $em->persist((new ProductAccessZone($produit->getId(), $espace))->setEstablishment($etablissement));
        }
        $this->entite(Equipement::class, ['libelle' => AccesFixtures::EQUIPEMENT_LIBELLE])->setAntiPassbackActif(false);
        $em->flush();

        self::ensureKernelShutdown();
    }

    /**
     * RÉSILIATION. Le billet ouvre pendant l'abonnement et plus après. Il est l'accès de
     * l'abonnement (la fiche le montre), et une formule sans terme ne lui donne aucune fin.
     * Témoin : le billet ordinaire vendu dans la même vente n'est pas touché.
     */
    public function testTheTicketStopsOpeningOnceTheSubscriptionIsTerminated(): void
    {
        [$gold, $entree] = $this->sell(entrance: true);
        $abonnement = $this->subscription();

        self::assertSame(['valide', null], $this->gate($gold), 'Pendant l\'abonnement, le billet ouvre.');
        self::assertNull($this->terminalEntry($gold)['validiteFin'], 'Formule sans terme (mensuel par défaut) : pas de fin de validité.');

        $curseur = $this->snapshotCursor();
        $this->terminate();

        self::assertSame(['refuse', 'droit_invalide'], $this->gate($gold), 'Abonnement résilié : son billet n\'ouvre plus.');
        self::assertTrue($this->assertInDelta($curseur, $gold, 'résiliation')['revoque'], 'Borne hors ligne en delta : le billet est révoqué.');
        self::assertSame(['valide', null], $this->gate($entree), 'Témoin : le billet d\'entrée vendu avec lui n\'est pas touché.');
        $statut = $this->em()->getRepository(StatutAccesFitness::class)->findOneBy(['abonnement' => $abonnement->getId()]);
        self::assertSame($gold, $statut?->getSupportIdentifiant(), 'Un seul accès : la fiche abonnement montre le billet remis au client.');
    }

    /**
     * RÉ-APPAIRER LE BILLET (perte, nouvelle carte) NE ROUVRE PAS UN ABONNEMENT RÉSILIÉ. L'appairage
     * par `billetSupportRef` re-projette le droit du billet, et la re-projection le remettait « valide ».
     */
    public function testRepairingTheTicketDoesNotReopenATerminatedSubscription(): void
    {
        [$gold] = $this->sell();
        $this->terminate();
        $billet = $this->em()->getRepository(BilletSupport::class)->findOneBy(['identifiantSupport' => $gold]);
        self::assertInstanceOf(BilletSupport::class, $billet);

        [$client, $entete] = $this->adminSurA();
        $client->request('POST', '/api/acces/appairages', $entete + ['json' => [
            'identifiantSupport' => 'RFID-PERTE-1', 'typeSupport' => 'RFID', 'billetSupportRef' => (string) $billet->getId(), 'mode' => 'caisse',
        ]]);
        self::assertResponseStatusCodeSame(201, 'Témoin : la nouvelle carte est bien appairée au droit du billet.');

        self::assertSame(['refuse', 'droit_invalide'], $this->gate('RFID-PERTE-1'), 'La nouvelle carte suit l\'abonnement résilié.');
        self::assertSame(['refuse', 'droit_invalide'], $this->gate($gold), 'Le billet reste coupé.');
    }

    /** IMPAYÉ : le recouvrement coupe le billet, et la régularisation le rouvre. */
    public function testTheTicketIsCutOnUnpaidAndReopensOnceSettled(): void
    {
        [$gold] = $this->sell();
        $reference = (string) $this->subscription()->getId();

        // Le service est relu après chaque requête : le noyau redémarre, et celui d'avant aussi.
        $this->recovery()->desactiver(AbonnementFitnessRedevablePort::TYPE, $reference);
        self::assertSame(['refuse', 'droit_invalide'], $this->gate($gold), 'Impayé : le billet de l\'abonnement n\'ouvre plus.');

        $this->recovery()->reevaluer(AbonnementFitnessRedevablePort::TYPE, $reference);
        self::assertSame(['valide', null], $this->gate($gold), 'Impayé régularisé : le billet rouvre avec l\'abonnement.');
    }

    /** TERME : une formule qui s'arrête au terme donne sa fin au billet, puis le coupe. */
    public function testAFixedTermFormulaGivesItsEndToTheTicket(): void
    {
        $this->setTermMode(TermRenewalMode::Suspend);
        [$gold] = $this->sell();
        $abonnement = $this->subscription();
        $fin = $abonnement->getDateFinEngagement();

        self::assertSame(['valide', null], $this->gate($gold), 'Avant le terme, le billet ouvre.');
        self::assertSame($fin->format(\DATE_ATOM), $this->terminalEntry($gold)['validiteFin'], 'Borne hors ligne : le billet finit avec l\'abonnement.');

        self::assertSame('suspendu', static::getContainer()->get(SubscriptionTermHandler::class)->process($this->subscription(), $fin->modify('+1 day')));
        self::assertSame(['refuse', 'droit_invalide'], $this->gate($gold), 'Au terme, le billet n\'ouvre plus.');
    }

    /**
     * UNE FORMULE PASSÉE DE « SUSPENDRE » À « MENSUEL » APRÈS LA VENTE : au terme, l'abonnement se
     * prolonge, et le billet perd la fin d'avant. Sinon l'adhérent qui paie serait refusé à la porte.
     */
    public function testATermThatRollsOnLiftsTheTicketEnd(): void
    {
        // Un mois d'engagement : le tarif de la reconduction doit se résoudre dans la saison des
        // fixtures, qui finit le 31/12/2026.
        $this->setTermMode(TermRenewalMode::Suspend, engagementMonths: 1);
        [$gold] = $this->sell();
        self::assertNotNull($this->terminalEntry($gold)['validiteFin'], 'Témoin : la formule à terme donne une fin au billet.');

        $this->setTermMode(TermRenewalMode::Monthly);
        $abonnement = $this->subscription();
        self::assertStringStartsWith('mensualise', static::getContainer()->get(SubscriptionTermHandler::class)->process($abonnement, $abonnement->getDateFinEngagement()->modify('+1 day')));

        self::assertNull($this->terminalEntry($gold)['validiteFin'], 'Prolongé au mois : le billet n\'a plus de fin.');
        self::assertSame(['valide', null], $this->gate($gold));
    }

    /**
     * ANNULER LA VENTE RÉSILIE L'ABONNEMENT QU'ELLE A CRÉÉ (décision de Maxime du 07/10). Sans frais,
     * motif « vente annulée » : plus aucune échéance à venir, mandat révoqué, accès coupé, et la
     * résiliation reste comme trace.
     */
    public function testCancellingTheSaleTerminatesItsSubscription(): void
    {
        [$gold] = $this->sell();

        self::assertSame(201, $this->cancelSale('Client parti'));

        $abonnement = $this->subscription();
        self::assertSame(MembershipStatus::Resilie, $abonnement->getStatut(), 'Vente annulée : l\'abonnement est résilié avec elle.');
        $resiliation = $this->em()->getRepository(Resiliation::class)->findOneBy(['abonnement' => $abonnement]);
        self::assertSame([StatutResiliation::Effective, 'Vente annulée (Client parti)'], [$resiliation?->getStatut(), $resiliation?->getMotif()], 'Trace : une résiliation effective, au motif de la vente annulée.');
        self::assertSame(0, $this->em()->getRepository(EcheanceSepa::class)->count(['abonnement' => $abonnement, 'statut' => StatutEcheanceSepa::AVenir]), 'Plus aucun prélèvement à venir.');
        self::assertSame(StatutMandatSepa::Revoque, $abonnement->getMandatSepa()?->getStatut());
        self::assertSame(['refuse', 'droit_invalide'], $this->gate($gold), 'L\'accès tombe avec l\'abonnement.');
    }

    /** Le motif reste obligatoire (#279) : refusée, l'annulation ne touche pas à l'abonnement. */
    public function testARefusedCancellationKeepsTheSubscription(): void
    {
        [$gold] = $this->sell();

        self::assertSame(422, $this->cancelSale('Bidon'));

        self::assertSame(MembershipStatus::Actif, $this->subscription()->getStatut());
        self::assertSame(['valide', null], $this->gate($gold));
    }

    /** Témoin : une vente sans abonnement s'annule comme avant, sans résiliation. */
    public function testASaleWithoutSubscriptionCancelsAsBefore(): void
    {
        $this->sell(gold: false, entrance: true);
        $resiliations = $this->em()->getRepository(Resiliation::class)->count([]);

        self::assertSame(201, $this->cancelSale('Erreur de saisie'));

        self::assertSame(StatutVente::Annulee, $this->em()->getRepository(Vente::class)->find(Uuid::fromString($this->saleId))?->getStatut());
        self::assertSame($resiliations, $this->em()->getRepository(Resiliation::class)->count([]), 'Aucune résiliation.');
    }

    // ── Outillage ─────────────────────────────────────────────────────────────────────────────

    /**
     * Vend au payeur, à la caisse classique, le Gold (formule nominative) et/ou l'entrée, et rend les
     * codes de leurs billets dans cet ordre.
     *
     * @return list<string>
     */
    private function sell(bool $gold = true, bool $entrance = false): array
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $payeur = (string) $this->entite(Client::class, ['email' => CrmFixtures::PAYEUR_EMAIL])->getId();
        $vente = $this->creerVente($client, $entete, $session['id']);
        $this->saleId = (string) $vente['id'];
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/client', $entete + ['json' => ['client' => $payeur]]);
        self::assertResponseIsSuccessful();

        $lignes = $gold ? [OffreFixtures::PRODUIT_GOLD => ['beneficiaire' => $payeur]] : [];
        if ($entrance) {
            $lignes[OffreFixtures::PRODUIT_ENTREE] = [];
        }
        foreach ($lignes as $produit => $extra) {
            $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + ['json' => [
                'produit' => '/api/produits/' . $this->idProduit($produit),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ] + $extra]);
            self::assertResponseIsSuccessful();
        }
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + ['json' => ['moyen' => 'cb']]);
        $reponse = $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete + ['json' => []]);
        self::assertLessThan(300, $reponse->getStatusCode(), (string) $reponse->getContent(false));

        $em = $this->em();
        $em->clear();
        $codes = [];
        foreach ($em->getRepository(BilletSupport::class)->findBy(['vente' => Uuid::fromString((string) $vente['id'])]) as $billet) {
            $produit = $em->getRepository(Produit::class)->find($billet->getLigne()?->getProduit());
            $codes[$produit?->getLibelleRecherche()] = (string) $billet->getIdentifiantSupport();
        }
        self::assertCount(\count($lignes), $codes, 'Témoin : chaque ligne émet son billet.');

        return array_values(array_map(static fn (string $produit): string => $codes[$produit], array_keys($lignes)));
    }

    /** Annule la dernière vente au guichet (`/ventes/{id}/annuler`) et rend le code HTTP. */
    private function cancelSale(string $motif): int
    {
        [$client, $entete] = $this->adminSurA();
        $reponse = $client->request('POST', '/api/ventes/' . $this->saleId . '/annuler', $entete + ['json' => ['motif' => $motif]]);
        $this->em()->clear();

        return $reponse->getStatusCode();
    }

    /** Résilie l'abonnement du jour, effet immédiat (demande hors engagement). */
    private function terminate(): void
    {
        // Relu : chaque requête HTTP du test redémarre le noyau, et l'abonnement d'avant serait détaché.
        $abonnement = $this->subscription();
        $resiliations = static::getContainer()->get(DemanderResiliationHandler::class);
        $resiliations->executerEffet($resiliations->demander($abonnement, $abonnement->getDateFinEngagement()->modify('+1 day'), 'départ', false, null));
    }

    private function recovery(): PropagationAccesHandler
    {
        /** @var PropagationAccesHandler $handler */
        $handler = static::getContainer()->get(PropagationAccesHandler::class);

        return $handler;
    }

    private function setTermMode(TermRenewalMode $mode, ?int $engagementMonths = null): void
    {
        $formule = $this->entite(Produit::class, ['libelleRecherche' => OffreFixtures::PRODUIT_GOLD])->getFormule();
        self::assertNotNull($formule);
        $formule->setRenouvellement([TermRenewalMode::CLE_FORMULE => $mode->value] + $formule->getRenouvellement());
        if ($engagementMonths !== null) {
            $formule->setEngagement(['dureeMin' => $engagementMonths] + ($formule->getEngagement() ?? []));
        }
        $this->em()->flush();
    }

    /** L'abonnement que la vente vient de créer (celui des fixtures date d'un mois). */
    private function subscription(): Membership
    {
        $em = $this->em();
        $em->clear();
        $abonnements = $em->getRepository(Membership::class)->findBy(['dateSouscription' => new \DateTimeImmutable('today')]);
        self::assertCount(1, $abonnements);

        return $abonnements[0];
    }

    /** @return array{0: string, 1: ?string} le résultat du passage à la borne de démonstration, et son motif */
    private function gate(string $code): array
    {
        $reponse = static::createClient()->request('POST', '/api/terminal/passages', $this->terminalEntete() + ['json' => [
            'equipementId' => $this->idEquipement(),
            'identifiantSupport' => $code,
            'cleIdempotence' => (string) Uuid::v4(),
        ]]);
        self::assertSame(200, $reponse->getStatusCode(), (string) $reponse->getContent(false));
        $passage = $reponse->toArray();

        return [$passage['resultat'], $passage['codeMotif']];
    }

    /** @return array<string, mixed> l'entrée du billet dans le snapshot complet d'une borne */
    private function terminalEntry(string $code): array
    {
        $entree = $this->fullSnapshotEntry($code);
        self::assertIsArray($entree, 'Le billet doit figurer dans le snapshot de la borne.');

        return $entree;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
