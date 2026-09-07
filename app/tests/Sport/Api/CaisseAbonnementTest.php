<?php

declare(strict_types=1);

namespace App\Tests\Sport\Api;

use ApiPlatform\Symfony\Bundle\Test\Client as ApiClient;
use App\Compta\DataFixtures\ComptaFixtures;
use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Client;
use App\Crm\Enum\TypeClient;
use App\Offre\DataFixtures\OffreFixtures;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Groupe;
use App\Sepa\DataFixtures\SepaFixtures;
use App\Sepa\Entity\MandatSepa;
use App\Sepa\Enum\StatutMandatSepa;
use App\Sport\Entity\AbonnementFitness;
use App\Sport\Entity\EcheanceSepa;
use App\Sport\Enum\StatutAbonnementFitness;
use App\Tests\Acces\AccesApiTestCase;
use App\Vente\Entity\Vente;
use App\Vente\Enum\StatutVente;
use App\Vente\Nf525\Entity\OperationScellee;
use App\Vente\Port\MandateChoice;
use App\Vente\Port\SaleSubscriptionInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * LA CAISSE CRÉE L'ABONNEMENT (arbitrage de Maxime du 07/09) — le parcours « vente d'un produit
 * d'abonnement au guichet → abonnement, mandat et échéancier créés », de bout en bout.
 *
 * ── POURQUOI CE PARCOURS, ET PAS DES FIXTURES POSÉES AU MILIEU ─────────────────────────────────
 *
 * Le cœur du lot est une ATOMICITÉ : la souscription s'exécute dans la transaction de scellement
 * NF525 de la vente. Un test qui partirait d'une vente déjà scellée et d'un abonnement déjà posé ne
 * verrait jamais ce qui compte — qu'un refus de souscription fait rollback le scel, et qu'une vente
 * scellée porte forcément son abonnement. On part donc d'une vraie vente au guichet, on la valide
 * par l'API, et on mesure les DEUX faces :
 *   1. le chemin heureux crée l'abonnement, relié à sa ligne, avec son mandat et son échéancier ;
 *   2. chaque refus (mandat manquant, bénéficiaire hors périmètre) NE consomme AUCUN numéro de
 *      chaîne — la vente reste rejouable.
 *
 * La première moitié est le témoin de la seconde : sans elle, un « aucun scel consommé » resterait
 * vert le jour où la validation cesserait de sceller quoi que ce soit, et prétendrait prouver un
 * rollback qu'elle ne mesure pas.
 *
 * Étend `AccesApiTestCase` pour son outillage caisse (session, vente, lignes, paiement, validation)
 * et pour la topologie PDV/Caisse de `VenteFixtures` ; charge en plus Compta + CRM + SEPA, requis
 * pour un payeur porteur d'un groupe et pour la configuration créancier que l'échéancier consulte.
 */
final class CaisseAbonnementTest extends AccesApiTestCase
{
    private const IBAN_DEMO = 'FR7630006000011234567890189';
    private const BIC_DEMO = 'AGRIFRPP';

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();
        foreach ([ComptaFixtures::class, CrmFixtures::class, SepaFixtures::class] as $classe) {
            $container->get($classe)->load($em);
        }

        self::ensureKernelShutdown();
    }

    /** Chemin heureux : la vente d'un abonnement au guichet crée l'abonnement, relié à sa ligne. */
    public function testVenteGuichetDunAbonnementLeCree(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $payeur = $this->entite(Client::class, ['email' => CrmFixtures::PAYEUR_EMAIL]);

        [$venteId, $ligneId] = $this->venteAbonnement($client, $entete, $session['id'], (string) $payeur->getId());

        $reponse = $client->request('POST', '/api/ventes/' . $venteId . '/valider', $entete + [
            'json' => ['abonnement' => [
                'mode' => 'counter',
                'iban' => self::IBAN_DEMO,
                'titulaire' => 'Jean Dupont',
                'bic' => self::BIC_DEMO,
            ]],
        ]);
        self::assertResponseIsSuccessful((string) $reponse->getContent(false));

        $em = $this->em();
        $abonnement = $em->getRepository(AbonnementFitness::class)
            ->findOneBy(['sourceSaleLineId' => Uuid::fromString($ligneId)]);
        self::assertInstanceOf(
            AbonnementFitness::class,
            $abonnement,
            'La vente au guichet doit créer l\'abonnement, relié à la ligne qui l\'a vendu.',
        );
        self::assertSame(StatutAbonnementFitness::Actif, $abonnement->getStatut());
        self::assertSame(3990, $abonnement->getMontantCentimes(), 'Le prix vient du tarif du produit (39,90 €), jamais saisi.');
        self::assertSame(
            (string) $payeur->getId(),
            (string) $abonnement->getPayeur()?->getId(),
            'Le payeur de l\'abonnement est le client de la vente.',
        );

        $mandat = $abonnement->getMandatSepa();
        self::assertNotNull($mandat, 'Souscrire sans mandat ne rend personne prélevable.');
        self::assertSame(StatutMandatSepa::Actif, $mandat->getStatut());
        self::assertSame(
            self::BIC_DEMO,
            $mandat->getBicDebiteur(),
            'Le BIC saisi au comptoir doit être porté par le mandat — sans lui, la remise pain.008 rend un <BIC> vide.',
        );

        // ⚠ 1ER MOIS AU COMPTOIR, SEPA DÈS LE 2E (arbitrage). La première échéance vaut 0 : le mois
        //    d'entrée est encaissé dans la vente, le prélèvement récurrent ne commence qu'au suivant.
        $premiere = $em->getRepository(EcheanceSepa::class)
            ->findOneBy(['abonnement' => $abonnement], ['dateProgrammee' => 'ASC']);
        self::assertInstanceOf(EcheanceSepa::class, $premiere, 'La souscription doit générer un échéancier.');
        self::assertSame(0, $premiere->getMontantCentimes(), 'Comptoir 1er mois : la 1re échéance SEPA vaut 0.');
    }

    /**
     * TÉMOIN D'ATOMICITÉ — un mandat manquant fait rollback le scellement.
     *
     * Le refus de souscription (`souscrire()` exige un mandat) est levé DANS la transaction de
     * scellement : la vente ne doit ni passer Validée, ni consommer un numéro de chaîne NF525.
     */
    public function testMandatManquantAnnuleLeScellement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $payeur = $this->entite(Client::class, ['email' => CrmFixtures::PAYEUR_EMAIL]);
        [$venteId, $ligneId] = $this->venteAbonnement($client, $entete, $session['id'], (string) $payeur->getId());

        $em = $this->em();
        $scellesAvant = (int) $em->getRepository(OperationScellee::class)->count([]);

        // mode « counter » mais sans IBAN ni titulaire : le mandat ne peut pas être signé.
        $reponse = $client->request('POST', '/api/ventes/' . $venteId . '/valider', $entete + [
            'json' => ['abonnement' => ['mode' => 'counter']],
        ]);
        self::assertSame(422, $reponse->getStatusCode(), (string) $reponse->getContent(false));

        $em->clear();
        $vente = $em->getRepository(Vente::class)->find(Uuid::fromString($venteId));
        self::assertInstanceOf(Vente::class, $vente);
        self::assertSame(StatutVente::EnCours, $vente->getStatut(), 'Le refus doit laisser la vente rejouable.');
        self::assertNull(
            $em->getRepository(AbonnementFitness::class)->findOneBy(['sourceSaleLineId' => Uuid::fromString($ligneId)]),
            'Une validation refusée ne doit créer aucun abonnement.',
        );
        self::assertSame(
            $scellesAvant,
            (int) $em->getRepository(OperationScellee::class)->count([]),
            'Le refus de souscription doit faire ROLLBACK le scellement : aucun numéro de chaîne consommé.',
        );
    }

    /**
     * TÉMOIN DE CLOISONNEMENT — une ligne qui désigne un adhérent d'un autre groupe est refusée
     * (404, échec fermé), sans rien sceller. `BeneficiaryResolver::forPurchase()` résout sans filtre ;
     * l'adaptateur doit refuser avant de l'appeler.
     */
    public function testBeneficiaireDunAutreGroupeEstRefuseSansSceller(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $payeur = $this->entite(Client::class, ['email' => CrmFixtures::PAYEUR_EMAIL]);

        // Entrée forgée : la ligne désigne, comme adhérent, un client d'un AUTRE groupe. Le contrôle
        // de requis à l'ajout de ligne ne regarde pas le périmètre (il exige juste UN bénéficiaire) ;
        // c'est l'adaptateur qui doit refuser à la validation.
        $em = $this->em();
        $intrusId = $this->creerClientAutreGroupe($em);
        [$venteId, $ligneId] = $this->venteAbonnement($client, $entete, $session['id'], (string) $payeur->getId(), $intrusId);

        $scellesAvant = (int) $em->getRepository(OperationScellee::class)->count([]);

        $reponse = $client->request('POST', '/api/ventes/' . $venteId . '/valider', $entete + [
            'json' => ['abonnement' => [
                'mode' => 'counter',
                'iban' => self::IBAN_DEMO,
                'titulaire' => 'Jean Dupont',
                'bic' => self::BIC_DEMO,
            ]],
        ]);
        self::assertSame(404, $reponse->getStatusCode(), (string) $reponse->getContent(false));

        $em->clear();
        $vente = $em->getRepository(Vente::class)->find(Uuid::fromString($venteId));
        self::assertInstanceOf(Vente::class, $vente);
        self::assertSame(StatutVente::EnCours, $vente->getStatut());
        self::assertNull(
            $em->getRepository(AbonnementFitness::class)->findOneBy(['sourceSaleLineId' => Uuid::fromString($ligneId)]),
            'Un bénéficiaire hors périmètre ne doit créer aucun abonnement.',
        );
        self::assertSame(
            $scellesAvant,
            (int) $em->getRepository(OperationScellee::class)->count([]),
            'Bénéficiaire hors périmètre : rien n\'est scellé.',
        );
    }

    /**
     * IDEMPOTENCE — la ligne source est UNIQUE. Rejouer la souscription sur une vente déjà traitée ne
     * crée pas de second abonnement : `sourceSaleLineId` court-circuite (et la contrainte unique en
     * base est le filet ultime).
     */
    public function testUneSecondeSouscriptionSurLaMemeLigneNeDoublePas(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $payeur = $this->entite(Client::class, ['email' => CrmFixtures::PAYEUR_EMAIL]);
        [$venteId, $ligneId] = $this->venteAbonnement($client, $entete, $session['id'], (string) $payeur->getId());

        $corpsMandat = ['mode' => 'counter', 'iban' => self::IBAN_DEMO, 'titulaire' => 'Jean Dupont', 'bic' => self::BIC_DEMO];
        $reponse = $client->request('POST', '/api/ventes/' . $venteId . '/valider', $entete + ['json' => ['abonnement' => $corpsMandat]]);
        self::assertResponseIsSuccessful((string) $reponse->getContent(false));

        $em = $this->em();
        $total = (int) $em->getRepository(AbonnementFitness::class)->count([]);
        self::assertSame(
            1,
            (int) $em->getRepository(AbonnementFitness::class)->count(['sourceSaleLineId' => Uuid::fromString($ligneId)]),
            'La vente doit avoir créé exactement un abonnement pour sa ligne.',
        );

        // Rejoue la souscription hors validation : l'adaptateur doit refuser le doublon.
        $vente = $em->getRepository(Vente::class)->find(Uuid::fromString($venteId));
        self::assertInstanceOf(Vente::class, $vente);
        /** @var SaleSubscriptionInterface $adapter */
        $adapter = static::getContainer()->get(SaleSubscriptionInterface::class);
        $adapter->createSubscriptionsFromSale($vente, MandateChoice::fromBody($corpsMandat));
        $em->flush();

        self::assertSame(
            $total,
            (int) $em->getRepository(AbonnementFitness::class)->count([]),
            'Rejouer la souscription sur la même ligne ne doit produire aucun second abonnement.',
        );
    }

    /**
     * MODE « EXISTING » — un second abonnement réutilise le mandat déjà signé par le client, sans en
     * créer un nouveau (un client, un mandat ; deux RUM feraient un rapprochement bancaire ambigu).
     */
    public function testModeExistingReutiliseLeMandatDuClient(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $payeur = $this->entite(Client::class, ['email' => CrmFixtures::PAYEUR_EMAIL]);
        $payeurId = (string) $payeur->getId();

        // 1re vente au comptoir : signe le mandat.
        [$vente1] = $this->venteAbonnement($client, $entete, $session['id'], $payeurId);
        $r1 = $client->request('POST', '/api/ventes/' . $vente1 . '/valider', $entete + [
            'json' => ['abonnement' => [
                'mode' => 'counter',
                'iban' => self::IBAN_DEMO,
                'titulaire' => 'Jean Dupont',
                'bic' => self::BIC_DEMO,
            ]],
        ]);
        self::assertResponseIsSuccessful((string) $r1->getContent(false));

        $em = $this->em();
        $mandatsApresPremiere = (int) $em->getRepository(MandatSepa::class)->count([]);
        self::assertGreaterThanOrEqual(1, $mandatsApresPremiere, 'La 1re vente doit avoir signé un mandat.');

        // 2e vente : réutilise le mandat existant, sans en resaisir l'IBAN.
        [$vente2, $ligne2] = $this->venteAbonnement($client, $entete, $session['id'], $payeurId);
        $r2 = $client->request('POST', '/api/ventes/' . $vente2 . '/valider', $entete + [
            'json' => ['abonnement' => ['mode' => 'existing']],
        ]);
        self::assertResponseIsSuccessful((string) $r2->getContent(false));

        $em2 = $this->em();
        self::assertSame(
            $mandatsApresPremiere,
            (int) $em2->getRepository(MandatSepa::class)->count([]),
            'Le mode existing ne doit signer AUCUN nouveau mandat.',
        );
        $abo2 = $em2->getRepository(AbonnementFitness::class)->findOneBy(['sourceSaleLineId' => Uuid::fromString($ligne2)]);
        self::assertInstanceOf(AbonnementFitness::class, $abo2);
        self::assertSame(StatutMandatSepa::Actif, $abo2->getMandatSepa()?->getStatut());
        self::assertSame(
            $payeurId,
            (string) $abo2->getMandatSepa()?->getClient()?->getId(),
            'Le mandat réutilisé est bien celui du payeur.',
        );
    }

    /**
     * MODE « EXISTING » sans mandat actif — refus (422) et rollback du scel. Un client qui n'a jamais
     * signé ne peut pas être prélevé : on ne fabrique pas un mandat vide en silence.
     */
    public function testModeExistingSansMandatActifEstRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);

        // Un client tout neuf sur l'établissement A : aucun mandat signé (le payeur de démonstration,
        // lui, en a déjà un par les fixtures — il réutiliserait, pas ce qu'on veut éprouver ici).
        $reference = $this->entite(Client::class, ['email' => CrmFixtures::PAYEUR_EMAIL]);
        $groupeA = $reference->getGroupe();
        $etabA = $reference->getEtablissementCreation();
        self::assertInstanceOf(Groupe::class, $groupeA);
        self::assertInstanceOf(Etablissement::class, $etabA);

        $em = $this->em();
        $sansMandatId = $this->creerClient($em, $groupeA, $etabA, 'sans.mandat@example.test');
        [$venteId, $ligneId] = $this->venteAbonnement($client, $entete, $session['id'], $sansMandatId);

        $scellesAvant = (int) $em->getRepository(OperationScellee::class)->count([]);

        $reponse = $client->request('POST', '/api/ventes/' . $venteId . '/valider', $entete + [
            'json' => ['abonnement' => ['mode' => 'existing']],
        ]);
        self::assertSame(422, $reponse->getStatusCode(), (string) $reponse->getContent(false));

        $em->clear();
        self::assertSame(
            StatutVente::EnCours,
            $em->getRepository(Vente::class)->find(Uuid::fromString($venteId))?->getStatut(),
        );
        self::assertNull($em->getRepository(AbonnementFitness::class)->findOneBy(['sourceSaleLineId' => Uuid::fromString($ligneId)]));
        self::assertSame(
            $scellesAvant,
            (int) $em->getRepository(OperationScellee::class)->count([]),
            'Un mode existing sans mandat actif ne doit rien sceller.',
        );
    }

    /**
     * MODE « PENDING » — la vente crée l'abonnement avec un mandat EN ATTENTE (IBAN capturé plus
     * tard). Le mandat n'étant pas actif, `GenerationRemiseHandler` l'exclut de toute remise : rien
     * n'est prélevé sur un IBAN absent.
     */
    public function testModePendingCreeUnMandatEnAttenteNonPrelevable(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $payeur = $this->entite(Client::class, ['email' => CrmFixtures::PAYEUR_EMAIL]);
        [$venteId, $ligneId] = $this->venteAbonnement($client, $entete, $session['id'], (string) $payeur->getId());

        $reponse = $client->request('POST', '/api/ventes/' . $venteId . '/valider', $entete + [
            'json' => ['abonnement' => ['mode' => 'pending']],
        ]);
        self::assertResponseIsSuccessful((string) $reponse->getContent(false));

        $em = $this->em();
        $abo = $em->getRepository(AbonnementFitness::class)->findOneBy(['sourceSaleLineId' => Uuid::fromString($ligneId)]);
        self::assertInstanceOf(AbonnementFitness::class, $abo, 'Le mode pending crée quand même l\'abonnement, lié à la vente.');
        $mandat = $abo->getMandatSepa();
        self::assertInstanceOf(MandatSepa::class, $mandat);
        self::assertSame(
            StatutMandatSepa::EnAttente,
            $mandat->getStatut(),
            'Le mandat est « en attente » : le filtre Actif de GenerationRemiseHandler l\'exclut de toute remise.',
        );
        self::assertSame('', $mandat->getIban4Derniers(), 'Aucun IBAN tant que le mandat est en attente.');
    }

    /** Compléter un mandat « en attente » capture l'IBAN et l'active (dès lors prélevable). */
    public function testCompleterUnMandatEnAttenteLActive(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $payeur = $this->entite(Client::class, ['email' => CrmFixtures::PAYEUR_EMAIL]);
        [$venteId, $ligneId] = $this->venteAbonnement($client, $entete, $session['id'], (string) $payeur->getId());
        $client->request('POST', '/api/ventes/' . $venteId . '/valider', $entete + [
            'json' => ['abonnement' => ['mode' => 'pending']],
        ]);
        self::assertResponseIsSuccessful();

        $em = $this->em();
        $abo = $em->getRepository(AbonnementFitness::class)->findOneBy(['sourceSaleLineId' => Uuid::fromString($ligneId)]);
        self::assertInstanceOf(AbonnementFitness::class, $abo);
        $mandatId = (string) $abo->getMandatSepa()?->getId();

        $reponse = $client->request('POST', '/api/sepa/mandats/' . $mandatId . '/completer', $entete + [
            'json' => ['iban' => self::IBAN_DEMO, 'titulaire' => 'Jean Dupont', 'bic' => self::BIC_DEMO],
        ]);
        self::assertResponseIsSuccessful((string) $reponse->getContent(false));

        $em2 = $this->em();
        $mandat = $em2->getRepository(MandatSepa::class)->find(Uuid::fromString($mandatId));
        self::assertInstanceOf(MandatSepa::class, $mandat);
        self::assertSame(StatutMandatSepa::Actif, $mandat->getStatut(), 'La complétion active le mandat.');
        self::assertNotSame('', $mandat->getIban4Derniers(), 'L\'IBAN est désormais capturé.');
        self::assertSame(self::BIC_DEMO, $mandat->getBicDebiteur());
    }

    /** On ne complète pas un mandat déjà actif : l'écraser effacerait un IBAN valide (déjà prélevé). */
    public function testCompleterUnMandatDejaActifEstRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $payeur = $this->entite(Client::class, ['email' => CrmFixtures::PAYEUR_EMAIL]);
        [$venteId, $ligneId] = $this->venteAbonnement($client, $entete, $session['id'], (string) $payeur->getId());
        // Mode comptoir : le mandat naît actif.
        $client->request('POST', '/api/ventes/' . $venteId . '/valider', $entete + [
            'json' => ['abonnement' => [
                'mode' => 'counter',
                'iban' => self::IBAN_DEMO,
                'titulaire' => 'Jean Dupont',
                'bic' => self::BIC_DEMO,
            ]],
        ]);
        self::assertResponseIsSuccessful();

        $em = $this->em();
        $abo = $em->getRepository(AbonnementFitness::class)->findOneBy(['sourceSaleLineId' => Uuid::fromString($ligneId)]);
        $mandatId = (string) $abo?->getMandatSepa()?->getId();

        $reponse = $client->request('POST', '/api/sepa/mandats/' . $mandatId . '/completer', $entete + [
            'json' => ['iban' => self::IBAN_DEMO, 'titulaire' => 'Jean Dupont'],
        ]);
        self::assertSame(422, $reponse->getStatusCode(), (string) $reponse->getContent(false));
    }

    /**
     * Vendre un produit-abonnement au comptoir SANS corps « abonnement » ne crée AUCUN abonnement :
     * la création est opt-in. Le même produit peut être vendu à seule fin comptable (encaissé
     * d'avance, produits constatés d'avance) sans ouvrir de mandat — c'est le cas qui cassait
     * `Compta\PcaTest` quand l'adaptateur naissait à chaque ligne-formule.
     */
    public function testVenteSansCorpsAbonnementNeCreeAucunAbonnement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $payeur = $this->entite(Client::class, ['email' => CrmFixtures::PAYEUR_EMAIL]);
        [$venteId, $ligneId] = $this->venteAbonnement($client, $entete, $session['id'], (string) $payeur->getId());

        // POST /valider SANS clé « abonnement » : la vente est validée, aucun abonnement n'est créé.
        $reponse = $client->request('POST', '/api/ventes/' . $venteId . '/valider', $entete + ['json' => []]);
        self::assertResponseIsSuccessful((string) $reponse->getContent(false));

        $em = $this->em();
        self::assertNull(
            $em->getRepository(AbonnementFitness::class)->findOneBy(['sourceSaleLineId' => Uuid::fromString($ligneId)]),
            'Sans corps « abonnement », vendre un produit-formule ne crée pas d\'abonnement (opt-in).',
        );
    }

    // ── Outillage ─────────────────────────────────────────────────────────────────────────────

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }

    /**
     * Ouvre une vente sur la session, y rattache le payeur, ajoute une ligne « Abonnement Gold »
     * (produit-formule SEPA nominatif, vendable au guichet à 39,90 €) et l'encaisse en espèces. Le
     * bénéficiaire (adhérent) de la ligne vaut le payeur par défaut ; un autre id permet le témoin
     * de cloisonnement.
     *
     * @param array<string, mixed> $entete
     *
     * @return array{0: string, 1: string} id vente, id ligne
     */
    private function venteAbonnement(ApiClient $client, array $entete, string $sessionId, string $payeurId, ?string $beneficiaireId = null): array
    {
        $vente = $this->creerVente($client, $entete, $sessionId);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/client', $entete + [
            'json' => ['client' => $payeurId],
        ]);
        $apres = $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_GOLD),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
                'beneficiaire' => $beneficiaireId ?? $payeurId,
            ],
        ])->toArray();
        $ligneId = (string) $apres['lignes'][0]['id'];
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + [
            'json' => ['moyen' => 'especes', 'montant' => '39.90'],
        ]);

        return [(string) $vente['id'], $ligneId];
    }

    /** Un client rattaché à un AUTRE groupe (Groupe B / Musée C), pour le témoin de cloisonnement. */
    private function creerClientAutreGroupe(EntityManagerInterface $em): string
    {
        $groupeB = $this->entite(Groupe::class, ['nom' => CrmFixtures::GROUPE_B_NOM]);
        $etabC = $this->entite(Etablissement::class, ['nom' => CrmFixtures::ETAB_C_NOM]);

        return $this->creerClient($em, $groupeB, $etabC, 'intrus.groupeb@example.test');
    }

    private function creerClient(EntityManagerInterface $em, Groupe $groupe, Etablissement $etab, string $email): string
    {
        $client = (new Client())
            ->setType(TypeClient::Physique)
            ->setGroupe($groupe)
            ->setEtablissementCreation($etab)
            ->setNom('Témoin')
            ->setPrenom('Test')
            ->setEmail($email);
        $em->persist($client);
        $em->flush();

        return (string) $client->getId();
    }
}
