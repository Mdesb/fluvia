<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use ApiPlatform\Symfony\Bundle\Test\Client as ApiClient;
use App\Compta\Entity\EtalementPca;
use App\Compta\Entity\MouvementPca;
use App\Compta\Enum\MethodePca;
use App\Compta\Enum\NaturePca;
use App\Compta\Enum\TypeMouvementPca;
use App\Compta\Nf525\ScellementEcritureHandler;
use App\Compta\Port\ProjectionPassageInterface;
use App\Compta\Regime\CompteLookupService;
use App\Compta\Regime\RegimeComptableResolver;
use App\Compta\Service\RepriseAuPassageHandler;
use App\Compta\Service\RepriseMensuellePcaHandler;
use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Client;
use App\Offre\DataFixtures\OffreFixtures;
use App\Tests\Compta\ComptaApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * US-L4-05, RG-M6-02/03 (CA-8) : un abonnement encaissé d'avance crédite le 487 ; un mois écoulé
 * déclenche une reprise au prorata temporis ; une carte multi-entrées déclenche une reprise au
 * passage. Le solde du 487 correspond à tout instant au reste-à-servir (rapprochement).
 */
final class PcaTest extends ComptaApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Le CRM, pour le client payeur de `vendreGold()`.
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();
        $container->get(CrmFixtures::class)->load($em);

        self::ensureKernelShutdown();
    }

    public function testAbonnementEncaisseDavanceCrediteLe487(): void
    {
        [$client, $entete] = $this->adminSurA();

        $goldId = $this->idProduit(OffreFixtures::PRODUIT_GOLD);
        $client->request('PATCH', '/api/produits/' . $goldId . '/compta', [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'] + $entete['headers'],
            'json' => ['reglePca' => 'etalement'],
        ]);
        self::assertResponseIsSuccessful();

        $vente = $this->vendreGold($client, $entete, $goldId);
        self::assertSame('validee', $vente['statut']);

        $client->request('POST', '/api/compta/ecritures/generer', $entete + [
            'json' => ['profilExploitant' => '/api/profil_exploitants/' . $this->idProfilExploitant()],
        ]);
        self::assertResponseIsSuccessful();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etalement = $em->getRepository(EtalementPca::class)->findOneBy(['venteOrigine' => $vente['id']]);
        self::assertNotNull($etalement, 'Un étalement PCA doit être créé pour un produit « à étaler » (RG-M6-02).');
        self::assertSame(NaturePca::AEtaler, $etalement->getNature());
        self::assertSame(MethodePca::ProrataTemporis, $etalement->getMethode());
        self::assertGreaterThan(0, $etalement->getMontantReporteCentimes());
        self::assertSame($etalement->getMontantReporteCentimes(), $etalement->getResteAServirCentimes());

        $dotation = $em->getRepository(MouvementPca::class)->findOneBy(['etalement' => $etalement->getId(), 'type' => TypeMouvementPca::Dotation]);
        self::assertNotNull($dotation);
        self::assertSame($etalement->getMontantReporteCentimes(), $dotation->getMontantCentimes());

        $etalementId = $etalement->getId();
        $resteAvant = $etalement->getResteAServirCentimes();

        // Rapprochement (US-L4-05) : reste-à-servir cohérent avec les mouvements.
        $rapprochement = $client->request('GET', '/api/compta/pca/' . $etalementId . '/rapprochement', $entete)->toArray();
        self::assertTrue($rapprochement['coherent']);
        self::assertSame($resteAvant, $rapprochement['resteAServirCentimes']);

        // Mois écoulé : reprise au prorata temporis, reste-à-servir décroît.
        /** @var RepriseMensuellePcaHandler $repriseHandler */
        $repriseHandler = static::getContainer()->get(RepriseMensuellePcaHandler::class);
        $dateReprise = (new \DateTimeImmutable((string) $vente['date']))->modify('+1 month');
        $nb = $repriseHandler->reprendre($this->profilExploitant(), $dateReprise);
        self::assertSame(1, $nb);

        // Re-fetch : le client de test reboote le kernel à chaque requête (l'EM/l'entité d'avant la
        // requête « rapprochement » deviennent obsolètes).
        /** @var EntityManagerInterface $emApres */
        $emApres = static::getContainer()->get('doctrine')->getManager();
        $etalementApres = $emApres->getRepository(EtalementPca::class)->find($etalementId);
        self::assertNotNull($etalementApres);
        self::assertLessThan($resteAvant, $etalementApres->getResteAServirCentimes(), 'Le reste-à-servir doit décroître après une reprise prorata temporis.');

        $reprise = $emApres->getRepository(MouvementPca::class)->findOneBy(['etalement' => $etalementId, 'type' => TypeMouvementPca::Reprise]);
        self::assertNotNull($reprise);
        self::assertNotNull($reprise->getEcritureLiee());
        self::assertTrue($reprise->getEcritureLiee()->estEquilibree());
    }

    public function testCarteMultiEntreesReprendAuPassage(): void
    {
        [, $entete] = $this->adminSurA();
        unset($entete); // Authentification non nécessaire : appel direct des services métier (Unit).

        $profil = $this->profilExploitant();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $compte487 = $this->entite(\App\Compta\Entity\CompteComptable::class, ['numero' => '487000']);

        $etalement = new EtalementPca();
        $etalement->setProfilExploitant($profil);
        $etalement->setProduit(\Symfony\Component\Uid\Uuid::v4());
        $etalement->setVenteOrigine(\Symfony\Component\Uid\Uuid::v4());
        $etalement->setNature(NaturePca::ALaConsommation);
        $etalement->setMethode(MethodePca::AuPassage);
        $etalement->setCompteReport($compte487);
        $etalement->setMontantReporteCentimes(1200); // 12 unités, 100 centimes/unité.
        $etalement->setResteAServirCentimes(1200);
        $etalement->setNbUnitesCarte(12);
        $etalement->setIdentifiantSupport('QR-DEMO-0001');
        $em->persist($etalement);
        $em->flush();

        $fake = new class implements ProjectionPassageInterface {
            public function compterPassagesAutorises(string $identifiantSupport, \DateTimeImmutable $depuis): int
            {
                return 3; // 3 passages consommés depuis la vente.
            }
        };

        $handler = new RepriseAuPassageHandler(
            $em,
            $fake,
            static::getContainer()->get(RegimeComptableResolver::class),
            static::getContainer()->get(CompteLookupService::class),
            static::getContainer()->get(ScellementEcritureHandler::class),
        );

        $nb = $handler->reprendre($etalement);
        self::assertSame(3, $nb);

        $em->refresh($etalement);
        self::assertSame(1200 - 3 * 100, $etalement->getResteAServirCentimes());

        $reprises = $em->getRepository(MouvementPca::class)->findBy(['etalement' => $etalement->getId(), 'type' => TypeMouvementPca::Reprise]);
        self::assertCount(3, $reprises);
        foreach ($reprises as $reprise) {
            self::assertSame(\App\Compta\Enum\FaitGenerateurPca::Passage, $reprise->getFaitGenerateur());
            self::assertTrue($reprise->getEcritureLiee()?->estEquilibree());
        }
    }

    public function testPcaDesactiveReconnaitDirectementSans487(): void
    {
        [$client, $entete] = $this->adminSurA();

        // Désactive le PCA (point EXPERT #3, défaut prudent en régie directe).
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $profil = $this->profilExploitant();
        $profil->setParametresRegime(new \App\Compta\ValueObject\ParametresRegime(pcaActif: false));
        $em->flush();

        $goldId = $this->idProduit(OffreFixtures::PRODUIT_GOLD);
        $client->request('PATCH', '/api/produits/' . $goldId . '/compta', [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'] + $entete['headers'],
            'json' => ['reglePca' => 'etalement'],
        ]);

        $vente = $this->vendreGold($client, $entete, $goldId);
        // Témoin : sans vente validée, « aucun étalement » plus bas serait vrai pour une autre raison.
        self::assertSame('validee', $vente['statut']);

        $client->request('POST', '/api/compta/ecritures/generer', $entete + [
            'json' => ['profilExploitant' => '/api/profil_exploitants/' . $this->idProfilExploitant()],
        ]);

        // Re-fetch : le client de test reboote le kernel à chaque requête.
        /** @var EntityManagerInterface $emApres */
        $emApres = static::getContainer()->get('doctrine')->getManager();
        $etalement = $emApres->getRepository(EtalementPca::class)->findOneBy(['venteOrigine' => $vente['id']]);
        self::assertNull($etalement, 'PCA désactivé : aucune écriture 487, reconnaissance directe en produit.');

        $ecriture = $emApres->getRepository(\App\Compta\Entity\EcritureComptable::class)->findOneBy(['venteOrigine' => $vente['id']]);
        self::assertNotNull($ecriture);
        self::assertTrue($ecriture->estEquilibree());
    }

    /**
     * Vend « Abonnement Gold » (39,90 €, espèces) au client payeur du CRM et valide la vente.
     *
     * ⚠ LE PAYEUR EST CE QUI MANQUAIT. Depuis la caisse → abonnement (16/09, `SaleSubscriptionAdapter`),
     * valider une ligne de produit-formule souscrit l'abonnement, et une vente anonyme est refusée : un
     * mandat SEPA suppose un débiteur nommé. Ce test vendait Gold sans client et levait ce 422 avant de
     * regarder le 487. Ce qu'il prouve n'a pas changé ; la vente redevient une vente permise.
     *
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed> la vente validée
     */
    private function vendreGold(ApiClient $client, array $entete, string $goldId): array
    {
        $payeurId = (string) $this->entite(Client::class, ['email' => CrmFixtures::PAYEUR_EMAIL])->getId();

        $session = $this->ouvrirSession($client, $entete);
        $vente = $client->request('POST', '/api/ventes', $entete + ['json' => ['session' => '/api/session_caisses/' . $session['id']]])->toArray();
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/client', $entete + ['json' => ['client' => $payeurId]]);
        self::assertResponseIsSuccessful();
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $goldId,
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
                'beneficiaire' => $payeurId, // produit nominatif (RG-M2-04) : l'adhérent est le payeur.
            ],
        ]);
        self::assertResponseIsSuccessful();
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + ['json' => ['moyen' => 'especes', 'montant' => '39.90']]);

        return $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete)->toArray();
    }
}
