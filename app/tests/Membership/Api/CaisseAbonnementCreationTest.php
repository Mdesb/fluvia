<?php

declare(strict_types=1);

namespace App\Tests\Membership\Api;

use ApiPlatform\Symfony\Bundle\Test\Client as ApiClient;
use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Client;
use App\Crm\Enum\TypeClient;
use App\Membership\Entity\Membership;
use App\Offre\DataFixtures\OffreFixtures;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Groupe;
use App\Sepa\Entity\MandatSepa;
use App\Sepa\Enum\StatutMandatSepa;
use App\Tests\Acces\AccesApiTestCase;
use App\Vente\Entity\Vente;
use App\Vente\Enum\StatutVente;
use App\Vente\Nf525\Entity\OperationScellee;
use App\Vente\Port\SaleSubscriptionInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;

/**
 * LA CAISSE CRÉE L'ABONNEMENT, APRÈS LE COMMIT (spec-caisse-abonnement CP-1 G-1/G-5, plan CP-2
 * É1/É2) — le parcours « vente d'un produit-formule au comptoir → validation → abonnement créé »,
 * de bout en bout.
 *
 * ── POURQUOI CE PARCOURS, ET PAS DES FIXTURES POSÉES AU MILIEU ─────────────────────────────────
 *
 * Le cœur de ce lot est une frontière transactionnelle : la souscription s'exécute APRÈS le commit
 * du scellement NF525, jamais dedans (correction du montage « atomique » faux de l'ancienne branche
 * `feature/caisse-abonnement`, qui créait l'abonnement DANS la transaction scellée). Un test qui
 * partirait d'une vente déjà scellée ne verrait jamais ce qui compte : qu'un refus du port NE FAIT
 * PAS rollback le scel. On part donc d'une vraie vente au comptoir, on la valide par l'API, et on
 * mesure les deux faces : le chemin heureux crée l'abonnement relié à sa ligne (témoin positif) ; un
 * refus du port (bénéficiaire hors périmètre) laisse la vente scellée et valide (témoin de la
 * frontière). Le premier est le témoin du second : sans lui, un « rien n'a fait rollback » resterait
 * vert le jour où la validation cesserait de créer quoi que ce soit.
 *
 * Étend `AccesApiTestCase` pour son outillage caisse (session, vente, lignes, paiement, validation)
 * et pour la topologie PDV/Caisse de `VenteFixtures` ; charge en plus CRM, requis pour un payeur
 * porteur d'un groupe (cloisonnement) et les fixtures `Groupe B` / `Établissement C` du témoin.
 */
final class CaisseAbonnementCreationTest extends AccesApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();
        $container->get(CrmFixtures::class)->load($em);

        self::ensureKernelShutdown();
    }

    /**
     * (1) TÉMOIN POSITIF — une vente comptoir validée avec une ligne à facette Formule crée 1
     * `Membership`, relié à sa ligne, avec un mandat SEPA « en attente » (G-3 : pas de lien de
     * signature dans ce lot).
     */
    public function testVenteAvecFacetteFormuleCreeUnAbonnement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $payeur = $this->entite(Client::class, ['email' => CrmFixtures::PAYEUR_EMAIL]);
        [$venteId, $ligneId] = $this->venteAbonnement($client, $entete, $session['id'], (string) $payeur->getId());

        $reponse = $client->request('POST', '/api/ventes/' . $venteId . '/valider', $entete);
        self::assertResponseIsSuccessful((string) $reponse->getContent(false));

        $em = $this->em();
        $abonnement = $em->getRepository(Membership::class)->findOneBy(['sourceSaleLineId' => Uuid::fromString($ligneId)]);
        self::assertInstanceOf(
            Membership::class,
            $abonnement,
            'La vente comptoir doit créer l\'abonnement, relié à la ligne qui l\'a vendu.',
        );
        self::assertSame(3990, $abonnement->getMontantCentimes(), 'Le prix vient du tarif résolu, jamais saisi.');
        self::assertSame(
            (string) $payeur->getId(),
            (string) $abonnement->getPayeur()?->getId(),
            'Le payeur de l\'abonnement est le client de la vente.',
        );

        $mandat = $abonnement->getMandatSepa();
        self::assertInstanceOf(MandatSepa::class, $mandat);
        self::assertSame(
            StatutMandatSepa::EnAttente,
            $mandat->getStatut(),
            'Ce lot (É1/É2) ne capte pas encore le mandat par lien (É5) : il naît en attente, sans IBAN.',
        );

        // La vente est bien scellée : la création n'a pas empêché le scellement NF525.
        self::assertSame(
            1,
            (int) $em->getRepository(OperationScellee::class)->count([]),
            'La vente doit être scellée NF525.',
        );
    }

    /** (2) TÉMOIN NÉGATIF — une vente sans ligne à facette Formule ne crée AUCUN abonnement. */
    public function testVenteSansFacetteFormuleNeCreeAucunAbonnement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $vente = $this->creerVente($client, $entete, $session['id']);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_ENTREE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ]);
        self::assertResponseIsSuccessful();
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + [
            'json' => ['moyen' => 'especes', 'montant' => '5.50'],
        ]);

        $reponse = $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete);
        self::assertResponseIsSuccessful((string) $reponse->getContent(false));

        $em = $this->em();
        self::assertSame(
            0,
            (int) $em->getRepository(Membership::class)->count([]),
            'Un produit sans facette Formule ne doit créer aucun abonnement.',
        );
    }

    /**
     * (3) TÉMOIN DE LA FRONTIÈRE — un refus du port (bénéficiaire hors groupe, échec fermé) laisse
     * la vente SCELLÉE ET VALIDE : pas de rollback. C'est la correction du montage « atomique » faux
     * de l'ancienne branche, où ce même refus faisait rollback le scellement.
     */
    public function testEchecDuPortNeFaitPasRollbackDuScellement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $payeur = $this->entite(Client::class, ['email' => CrmFixtures::PAYEUR_EMAIL]);

        $em = $this->em();
        $intrusId = $this->creerClientAutreGroupe($em);
        [$venteId, $ligneId] = $this->venteAbonnement($client, $entete, $session['id'], (string) $payeur->getId(), $intrusId);

        $reponse = $client->request('POST', '/api/ventes/' . $venteId . '/valider', $entete);
        self::assertSame(404, $reponse->getStatusCode(), (string) $reponse->getContent(false));

        $em->clear();
        $vente = $em->getRepository(Vente::class)->find(Uuid::fromString($venteId));
        self::assertInstanceOf(Vente::class, $vente);
        self::assertSame(
            StatutVente::Validee,
            $vente->getStatut(),
            'Le refus du port est postérieur au commit : la vente doit rester SCELLÉE et VALIDE.',
        );
        self::assertSame(
            1,
            (int) $em->getRepository(OperationScellee::class)->count([]),
            'Le scellement NF525 doit avoir eu lieu malgré l\'échec du port (pas de rollback).',
        );
        self::assertNull(
            $em->getRepository(Membership::class)->findOneBy(['sourceSaleLineId' => Uuid::fromString($ligneId)]),
            'Un bénéficiaire hors périmètre ne doit créer aucun abonnement.',
        );
    }

    /**
     * (4) IDEMPOTENCE — rejouer la souscription sur une ligne déjà traitée (reprise explicite après
     * un échec, G-5) ne crée pas de second abonnement.
     */
    public function testRejouerLaSouscriptionNeDoublePasLAbonnement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $payeur = $this->entite(Client::class, ['email' => CrmFixtures::PAYEUR_EMAIL]);
        [$venteId, $ligneId] = $this->venteAbonnement($client, $entete, $session['id'], (string) $payeur->getId());

        $reponse = $client->request('POST', '/api/ventes/' . $venteId . '/valider', $entete);
        self::assertResponseIsSuccessful((string) $reponse->getContent(false));

        $em = $this->em();
        self::assertSame(
            1,
            (int) $em->getRepository(Membership::class)->count(['sourceSaleLineId' => Uuid::fromString($ligneId)]),
            'La vente doit avoir créé exactement un abonnement pour sa ligne.',
        );

        // Reprise explicite : on rejoue le port directement sur la même vente (déjà Validée).
        $vente = $em->getRepository(Vente::class)->find(Uuid::fromString($venteId));
        self::assertInstanceOf(Vente::class, $vente);
        /** @var SaleSubscriptionInterface $port */
        $port = static::getContainer()->get(SaleSubscriptionInterface::class);
        $port->createSubscriptionsFromSale($vente);
        $em->flush();

        self::assertSame(
            1,
            (int) $em->getRepository(Membership::class)->count(['sourceSaleLineId' => Uuid::fromString($ligneId)]),
            'Rejouer la souscription sur la même ligne ne doit produire aucun second abonnement.',
        );
    }

    /** @return iterable<string, array{string}> */
    public static function fuseaux(): iterable
    {
        yield 'UTC+14' => ['Pacific/Kiritimati'];
        yield 'UTC-11' => ['Pacific/Pago_Pago'];
    }

    /**
     * (5) LE CONTRAT COMMENCE LE JOUR DE L'ÉTABLISSEMENT, PAS LE JOUR UTC DE LA VENTE.
     *
     * L'instant de la vente allait tel quel dans des colonnes `date` (mesuré le 07/10/2026) : une
     * vente entre 00:00 et 01:00 ou 02:00 à Paris ouvrait un contrat daté de la veille, et son terme
     * tombait un jour plus tôt. Les établissements passent à UTC+14 puis UTC-11 : à toute heure,
     * l'un des deux n'a pas le jour UTC.
     */
    #[DataProvider('fuseaux')]
    public function testLAbonnementCommenceLeJourDeLEtablissement(string $fuseau): void
    {
        [$client, $entete] = $this->adminSurA();
        foreach ($this->em()->getRepository(Etablissement::class)->findAll() as $etablissement) {
            $etablissement->setFuseauHoraire($fuseau);
        }
        $this->em()->flush();
        $session = $this->ouvrirSession($client, $entete);
        $payeur = $this->entite(Client::class, ['email' => CrmFixtures::PAYEUR_EMAIL]);
        [$venteId, $ligneId] = $this->venteAbonnement($client, $entete, $session['id'], (string) $payeur->getId());

        $reponse = $client->request('POST', '/api/ventes/' . $venteId . '/valider', $entete);
        self::assertResponseIsSuccessful((string) $reponse->getContent(false));

        $em = $this->em();
        $em->clear();
        $abonnement = $em->getRepository(Membership::class)->findOneBy(['sourceSaleLineId' => Uuid::fromString($ligneId)]);
        self::assertSame(
            (new \DateTimeImmutable('now', new \DateTimeZone($fuseau)))->format('Y-m-d'),
            $abonnement?->getDateSouscription()->format('Y-m-d'),
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
     * (produit-formule SEPA à 39,90 €) et l'encaisse en espèces. Le bénéficiaire (adhérent) de la
     * ligne vaut le payeur par défaut ; un autre id permet le témoin de cloisonnement.
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
        self::assertResponseIsSuccessful();
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
        self::assertResponseIsSuccessful();

        return [(string) $vente['id'], $ligneId];
    }

    /** Un client rattaché à un AUTRE groupe (Groupe B / Musée C), pour le témoin de cloisonnement. */
    private function creerClientAutreGroupe(EntityManagerInterface $em): string
    {
        $groupeB = $this->entite(Groupe::class, ['nom' => CrmFixtures::GROUPE_B_NOM]);
        $etabC = $this->entite(Etablissement::class, ['nom' => CrmFixtures::ETAB_C_NOM]);

        $intrus = (new Client())
            ->setType(TypeClient::Physique)
            ->setGroupe($groupeB)
            ->setEtablissementCreation($etabC)
            ->setNom('Témoin')
            ->setPrenom('Cloisonnement')
            ->setEmail('intrus.groupeb@example.test');
        $em->persist($intrus);
        $em->flush();

        return (string) $intrus->getId();
    }
}
