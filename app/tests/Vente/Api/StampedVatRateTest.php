<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\MappingComptable;
use App\Compta\Entity\TauxTva;
use App\Compta\Enum\VatCategory;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Produit;
use App\Organisation\Entity\Etablissement;
use App\Tests\Vente\VenteApiTestCase;
use App\Vente\Entity\LigneVente;
use App\Vente\Entity\Vente;
use App\Vente\Port\SaleFiscalContextInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * LE TAUX EST GRAVÉ SUR LA LIGNE, DEPUIS LA CORRESPONDANCE COMPTABLE (D123, Q-B1 ; G-7, É15).
 *
 * `LigneVente` ne portait aucun taux : la TVA d'une vente n'existait qu'après coup, relue le jour du
 * calcul. Un taux changé, ou un `TauxTva` corrigé par `PATCH`, réécrivait donc le passé. La ligne grave
 * désormais à sa création la valeur, la catégorie EN 16931, le libellé et la référence du taux de la
 * correspondance de sa catégorie, la même que lisent les écritures. `Produit::$tauxTva` n'est pas lu.
 *
 * Le jeu de test : la catégorie comptable de l'offre est mise en correspondance avec « Taux normal 20 % »
 * sur le site A (`ComptaFixtures`).
 */
final class StampedVatRateTest extends VenteApiTestCase
{
    public function testTheLineStampsTheRateOfItsAccountingMappingAndIgnoresTheProductRate(): void
    {
        $taux = $this->mapping()->getTauxTva();
        self::assertInstanceOf(TauxTva::class, $taux);
        $taux->setVatCategory(VatCategory::Standard);
        // Le taux du produit (échéances d'abonnement, U-1) dit 10 % : la ligne ne doit pas le lire.
        $this->produit(OffreFixtures::PRODUIT_ENTREE)->setTauxTva('10.00');
        $this->em()->flush();

        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $vente = $this->creerVente($client, $entete, $this->ouvrirSession($client, $entete)['id']);
        $this->ajouterLigne($client, $entete, $vente['id']);

        $ligne = $client->request('GET', '/api/ventes/' . $vente['id'], $entete)->toArray()['lignes'][0];
        self::assertSame(
            ['20.00', 'S', 'Taux normal 20 %', (string) $taux->getId()],
            [$ligne['tauxTva'] ?? null, $ligne['tauxTvaCategorie'] ?? null, $ligne['tauxTvaLibelle'] ?? null, $ligne['tauxTvaRef'] ?? null],
        );
    }

    /** Après la vente : la correspondance passe à 10 %, puis le taux vendu est corrigé par `PATCH`. La ligne ne bouge pas. */
    public function testTheStampedRateSurvivesAMappingChangeAndAPatchOfTheRate(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $session = $this->ouvrirSession($client, $entete)['id'];
        $vente = $this->creerVente($client, $entete, $session);
        $this->ajouterLigne($client, $entete, $vente['id']);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + ['json' => ['moyen' => 'especes', 'montant' => '20.00']]);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();

        $mapping = $this->mapping();
        $vendu = $mapping->getTauxTva();
        self::assertInstanceOf(TauxTva::class, $vendu);
        $mapping->setTauxTva($this->taux('10.00'));
        $this->em()->flush();
        $client->request('PATCH', '/api/taux_tvas/' . $vendu->getId(), [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => $entete['headers'] + ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['taux' => '19.60', 'libelle' => 'Taux corrigé après coup'],
        ]);
        self::assertResponseIsSuccessful('Témoin : le taux vendu a bien été modifié.');

        $ligne = $client->request('GET', '/api/ventes/' . $vente['id'], $entete)->toArray()['lignes'][0];
        self::assertSame(['20.00', 'Taux normal 20 %'], [$ligne['tauxTva'] ?? null, $ligne['tauxTvaLibelle'] ?? null]);

        // Témoin : une ligne neuve voit la nouvelle correspondance.
        $neuve = $this->creerVente($client, $entete, $session);
        $this->ajouterLigne($client, $entete, $neuve['id']);
        $ligne = $client->request('GET', '/api/ventes/' . $neuve['id'], $entete)->toArray()['lignes'][0];
        self::assertSame('10.00', $ligne['tauxTva'] ?? null);
    }

    /** L'écouteur grave, il ne refuse pas, et il n'invente rien : sans correspondance valide, la ligne reste sans taux, jamais à 0 %. */
    public function testAnInvalidMappingStampsNothingRatherThanZero(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $vente = $this->creerVente($client, $entete, $this->ouvrirSession($client, $entete)['id']);
        $this->mapping()->getTauxTva()?->setActif(false);
        $this->em()->flush();

        // Une ligne écrite sans passer par un créateur qui refuse (comme le fait un rejeu hors ligne).
        $em = $this->em();
        $venteEntite = $em->getRepository(Vente::class)->find($vente['id']);
        self::assertInstanceOf(Vente::class, $venteEntite);
        $ligne = (new LigneVente())->setProduit($this->produit(OffreFixtures::PRODUIT_ENTREE)->getId())->setPrixUnitaire('5.50');
        $venteEntite->addLigne($ligne);
        $em->persist($ligne);
        $em->flush();
        $em->clear();

        $relue = $em->getRepository(LigneVente::class)->find($ligne->getId());
        self::assertInstanceOf(LigneVente::class, $relue);
        self::assertNotNull($relue->getLibelleProduit(), 'Témoin : l\'écouteur est bien passé sur cette ligne.');
        self::assertSame([null, null, null, null], [$relue->getTauxTva(), $relue->getTauxTvaCategorie(), $relue->getTauxTvaLibelle(), $relue->getTauxTvaRef()]);
    }

    /** Chaque maillon manquant est nommé : produit, catégorie, profil du site, correspondance, compte, taux. */
    public function testTheAdapterNamesTheMissingLink(): void
    {
        /** @var SaleFiscalContextInterface $port */
        $port = static::getContainer()->get(SaleFiscalContextInterface::class);
        $siteA = $this->site(SocleFixtures::ETAB_A_NOM);
        $entree = $this->produit(OffreFixtures::PRODUIT_ENTREE)->getId();

        $resolu = $port->vatRateFor($entree, $siteA);
        self::assertTrue($resolu->isResolved());
        self::assertSame(['20.00', 'Taux normal 20 %'], [$resolu->rate, $resolu->label]);

        $refus = static fn ($r): string => (string) $r->refusal;
        self::assertStringContainsString('aucun produit', $refus($port->vatRateFor(Uuid::v4(), $siteA)));
        self::assertStringContainsString('Patinoire B', $refus($port->vatRateFor($entree, $this->site(SocleFixtures::ETAB_B_NOM))));
        self::assertStringContainsString('aucun profil', $refus($port->vatRateFor($entree, null)));

        $sansCategorie = $this->produit(OffreFixtures::PRODUIT_GOLD);
        foreach ($sansCategorie->getCategories()->toArray() as $categorie) {
            $sansCategorie->removeCategorie($categorie);
        }
        $this->em()->flush();
        self::assertStringContainsString('PRD-GOLD01', $refus($port->vatRateFor($sansCategorie->getId(), $siteA)));

        $mapping = $this->mapping();
        $mapping->getCompteProduit()?->setActif(false);
        $this->em()->flush();
        self::assertStringContainsString(OffreFixtures::CAT_COMPTABLE, $refus($port->vatRateFor($entree, $siteA)), 'Compte de produit inactif.');
        $mapping->getCompteProduit()?->setActif(true);
        $mapping->getTauxTva()?->setActif(false);
        $this->em()->flush();
        self::assertStringContainsString(OffreFixtures::CAT_COMPTABLE, $refus($port->vatRateFor($entree, $siteA)), 'Taux inactif.');
        $this->em()->remove($mapping);
        $this->em()->flush();
        self::assertStringContainsString(OffreFixtures::CAT_COMPTABLE, $refus($port->vatRateFor($entree, $siteA)), 'Aucune correspondance.');
    }

    /** @param array<string, mixed> $entete */
    private function ajouterLigne(object $client, array $entete, string $venteId): void
    {
        $client->request('POST', '/api/ventes/' . $venteId . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_ENTREE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ]);
        self::assertResponseIsSuccessful();
    }

    private function mapping(): MappingComptable
    {
        $mapping = $this->em()->getRepository(MappingComptable::class)->findOneBy([]);
        self::assertInstanceOf(MappingComptable::class, $mapping, 'La correspondance de ComptaFixtures manque.');
        self::assertInstanceOf(CompteComptable::class, $mapping->getCompteProduit());

        return $mapping;
    }

    private function taux(string $valeur): TauxTva
    {
        $taux = $this->em()->getRepository(TauxTva::class)->findOneBy(['taux' => $valeur, 'actif' => true]);
        self::assertInstanceOf(TauxTva::class, $taux);

        return $taux;
    }

    private function produit(string $libelle): Produit
    {
        $produit = $this->em()->getRepository(Produit::class)->findOneBy(['libelleRecherche' => $libelle]);
        self::assertInstanceOf(Produit::class, $produit);

        return $produit;
    }

    private function site(string $nom): Etablissement
    {
        $site = $this->em()->getRepository(Etablissement::class)->findOneBy(['nom' => $nom]);
        self::assertInstanceOf(Etablissement::class, $site);

        return $site;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
