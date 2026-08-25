<?php

declare(strict_types=1);

namespace App\Tests\Subscription\Integration;

use App\Compta\Entity\TauxTva;
use App\Crm\Entity\Client;
use App\Crm\Enum\TypeClient;
use App\DataFixtures\SocleFixtures;
use App\Facturation\Entity\Facture;
use App\Facturation\Entity\ParametreFacturationEtablissement;
use App\Facturation\Enum\StatutFacture;
use App\Facturation\Service\EmettreFactureDirecteHandler;
use App\Facturation\Service\FactureDirecteBuilder;
use App\Facturation\Service\ResolveurComptesFacturation;
use App\Fonctionnalite\Service\CatalogueCapacites;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Service\EditorTenantResolver;
use App\Securite\Entity\Utilisateur;
use App\Subscription\Entity\Plan;
use App\Subscription\Entity\Subscription;
use App\Subscription\Entity\SubscriptionInvoice;
use App\Subscription\Enum\SubscriptionStatus;
use App\Subscription\Exception\InvoicingRefusedException;
use App\Subscription\Service\ProrationCalculator;
use App\Subscription\Service\SubscriptionInvoicer;
use App\Tests\Facturation\FacturationApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ED-7 — la facture mensuelle d'un abonnement.
 *
 * **Test d'intégration contre la vraie chaîne de facturation**, et pas contre un double. Ce qu'on
 * vérifie n'est pas que le service compose un objet : c'est qu'une facture d'abonnement traverse
 * réellement la numérotation, l'écriture comptable et le scellement NF525 — et qu'elle ne les
 * traverse **qu'une fois par mois**.
 *
 * Il étend la base de test de `Facturation` plutôt que d'en recopier le montage : profil exploitant,
 * journaux, comptes et taux de TVA sont soixante lignes de fixtures dont aucune ne m'appartient.
 */
final class SubscriptionInvoicerTest extends FacturationApiTestCase
{
    private const CLIENT = 'Camping des Pins';

    protected function setUp(): void
    {
        parent::setUp();

        $id = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $_ENV['EDITOR_TENANT_ID'] = $id;
        $_SERVER['EDITOR_TENANT_ID'] = $id;
    }

    /** Le chemin nominal : une facture émise, scellée, avec une ligne par élément vendu. */
    public function testUnAbonnementActifProduitUneFactureScellee(): void
    {
        $abonnement = $this->abonnementActif();

        $registre = $this->facturier()->facturerLeMois($abonnement, $this->mois(), $this->auteur(), $this->taux());

        self::assertInstanceOf(SubscriptionInvoice::class, $registre);

        $facture = $this->em()->getRepository(Facture::class)->find($registre->getInvoiceId());
        self::assertInstanceOf(Facture::class, $facture);

        // Émise, donc numérotée et scellée : c'est le handler du module qui l'a fait, pas nous.
        self::assertNotNull($facture->getNumero(), 'une facture émise porte un numéro');
        self::assertSame(StatutFacture::EnAttentePaiement, $facture->getStatut());

        // Une ligne pour la formule, une par option : le client doit reconnaître ce qu'il a coché.
        self::assertCount(2, $facture->getLignes());

        $designations = array_map(
            static fn ($l): string => $l->getDesignation(),
            $facture->getLignes()->toArray(),
        );
        self::assertStringContainsString('Essentiel', $designations[0]);
        self::assertStringContainsString('Option', $designations[1]);
        // Le libellé du module, jamais son code : « reservation » sur une facture n'aide personne.
        self::assertStringNotContainsString('reservation', $designations[1]);
    }

    /**
     * **Le test qui protège l'argent** : facturer deux fois le même mois ne produit qu'une facture.
     *
     * Un ordonnanceur qui repasse, une relance manuelle après un doute, deux exploitants qui
     * cliquent — aucun ne doit produire un second prélèvement.
     */
    public function testFacturerDeuxFoisLeMemeMoisNeProduitQuUneFacture(): void
    {
        $abonnement = $this->abonnementActif();

        $premier = $this->facturier()->facturerLeMois($abonnement, $this->mois(), $this->auteur(), $this->taux());
        // Un autre jour du même mois : c'est bien la même période de facturation.
        $second = $this->facturier()->facturerLeMois($abonnement, $this->mois()->modify('+9 days'), $this->auteur(), $this->taux());

        self::assertTrue($premier->getId()->equals($second->getId()));
        self::assertTrue($premier->getInvoiceId()->equals($second->getInvoiceId()));
        self::assertCount(1, $this->em()->getRepository(SubscriptionInvoice::class)->findAll());
        self::assertCount(1, $this->em()->getRepository(Facture::class)->findBy(['origine' => \App\Facturation\Enum\OrigineFacture::VenteATerme]));
    }

    /** Le mois suivant, en revanche, se facture. */
    public function testLeMoisSuivantSeFactureNormalement(): void
    {
        $abonnement = $this->abonnementActif();

        $premier = $this->facturier()->facturerLeMois($abonnement, $this->mois(), $this->auteur(), $this->taux());
        $suivant = $this->facturier()->facturerLeMois($abonnement, $this->mois()->modify('+1 month'), $this->auteur(), $this->taux());

        self::assertFalse($premier->getInvoiceId()->equals($suivant->getInvoiceId()));
        self::assertCount(2, $this->em()->getRepository(SubscriptionInvoice::class)->findAll());
    }

    /**
     * Le taux designe dans le parametrage est utilise sans qu'on ait a le repeter.
     *
     * C'est le chemin nominal une fois l'editeur configure : l'exploitant choisit une fois, et la
     * facturation — a l'ecran comme en ligne de commande — n'a plus a poser la question.
     */
    public function testLeTauxDesigneDansLeParametrageEstUtilise(): void
    {
        $parametre = $this->em()->getRepository(ParametreFacturationEtablissement::class)
            ->findOneBy(['profilExploitant' => $this->profilExploitant()]);
        \assert($parametre instanceof ParametreFacturationEtablissement);

        $parametre->setTauxTvaAbonnement($this->taux());
        $this->em()->flush();

        $abonnement = $this->abonnementActif();

        // Aucun taux passe en argument : il doit venir du parametrage.
        $registre = $this->facturier()->facturerLeMois($abonnement, $this->mois(), $this->auteur());

        $facture = $this->em()->getRepository(Facture::class)->find($registre->getInvoiceId());
        self::assertInstanceOf(Facture::class, $facture);
        self::assertNotNull($facture->getNumero());
    }

    /**
     * Un abonnement au panier ne se facture pas — il n'a pas été payé.
     *
     * Le résilié non plus. Le suspendu, si : la suspension coupe l'exposition des modules, pas la
     * dette (RG-ED-06).
     */
    public function testUnAbonnementAuPanierNeSeFacturePas(): void
    {
        $abonnement = $this->abonnement();

        $this->expectException(InvoicingRefusedException::class);

        $this->facturier()->facturerLeMois($abonnement, $this->mois(), $this->auteur(), $this->taux());
    }

    /**
     * Sans taux explicite, l'ambiguïté est refusée avec un message qui dit quoi faire.
     *
     * Le taux applicable aux abonnements se lit dans le parametrage de l'editeur, et il n'y a AUCUNE
     * valeur par defaut : le profil porte quatre taux actifs, donc en poser un d'office reviendrait a
     * se tromper une fois sur quatre. `null` veut dire « non decide », jamais « exonere » —
     * l'exoneration se dit par un taux a zero, qui facture normalement.
     */
    public function testSansTauxDesigneLaFacturationEstRefuseeAvecUnMessageUtile(): void
    {
        $abonnement = $this->abonnementActif();

        try {
            $this->facturier()->facturerLeMois($abonnement, $this->mois(), $this->auteur());
            self::fail('la facturation aurait dû être refusée');
        } catch (InvoicingRefusedException $refus) {
            self::assertStringContainsString('Aucun taux de TVA', $refus->getMessage());
            self::assertStringContainsString('parametrage', $refus->getMessage());
        }

        // Et le mois n'est pas condamné : la réservation a été défaite.
        self::assertCount(0, $this->em()->getRepository(SubscriptionInvoice::class)->findAll());
    }

    // ---------------------------------------------------------------- montage

    private function mois(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-01 09:00:00');
    }

    private function facturier(): SubscriptionInvoicer
    {
        $c = static::getContainer();

        /** @var ResolveurComptesFacturation $comptes */
        $comptes = $c->get(ResolveurComptesFacturation::class);
        /** @var FactureDirecteBuilder $builder */
        $builder = $c->get(FactureDirecteBuilder::class);
        /** @var EmettreFactureDirecteHandler $emetteur */
        $emetteur = $c->get(EmettreFactureDirecteHandler::class);
        /** @var CatalogueCapacites $capacites */
        $capacites = $c->get(CatalogueCapacites::class);

        return new SubscriptionInvoicer(
            $this->em(),
            new EditorTenantResolver($this->em(), $this->idEtablissement(SocleFixtures::ETAB_A_NOM)),
            $comptes,
            $builder,
            $emetteur,
            $capacites,
            new ProrationCalculator(),
        );
    }

    private function taux(): TauxTva
    {
        $taux = $this->em()->getRepository(TauxTva::class)->find($this->idTauxTva('Taux normal 20 %'));
        \assert($taux instanceof TauxTva);

        return $taux;
    }

    private function auteur(): Utilisateur
    {
        $auteur = $this->em()->getRepository(Utilisateur::class)->find($this->idAdmin());
        \assert($auteur instanceof Utilisateur);

        return $auteur;
    }

    private function abonnement(): Subscription
    {
        $editeur = $this->em()->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        \assert($editeur instanceof Etablissement);

        $client = (new Client())
            ->setType(TypeClient::Morale)
            ->setRaisonSociale(self::CLIENT)
            ->setEmail('contact@campingdespins.test')
            ->setGroupe($editeur->getRegion()?->getGroupe())
            ->setEtablissementCreation($editeur);
        $this->em()->persist($client);
        $this->em()->flush();

        $plan = (new Plan())
            ->setCode('essentiel')
            ->setLabel('Essentiel')
            ->setMonthlyPriceCents(4900)
            ->setIncludedCapabilities(['controle_acces'])
            ->setActive(true);
        $this->em()->persist($plan);

        $abonnement = (new Subscription())
            ->setCustomerReference($client->getId()->toRfc4122())
            ->setPlan($plan);
        $abonnement->addOption('reservation', 1500, $this->mois()->modify('-1 month'));

        $this->em()->persist($abonnement);
        $this->em()->flush();

        return $abonnement;
    }

    private function abonnementActif(): Subscription
    {
        $abonnement = $this->abonnement();
        $abonnement->transitionTo(SubscriptionStatus::Active, $this->mois()->modify('-1 month'));
        $this->em()->flush();

        return $abonnement;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
