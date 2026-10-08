<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\Compta\Entity\MappingComptable;
use App\Compta\Entity\TauxTva;
use App\Offre\DataFixtures\OffreFixtures;
use App\Tests\Vente\VenteApiTestCase;
use App\Vente\Entity\LigneVente;
use App\Vente\Entity\Vente;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * UNE LIGNE SANS TAUX EST REFUSÉE À SA CRÉATION, AVANT TOUT PAIEMENT (D123, Q-B3 ; G-9, É17).
 *
 * Sans correspondance comptable valide, la caisse créait la ligne, encaissait, scellait : la vente
 * sortait des écritures (`MappingComptableGuard`) et son ticket aurait dû inventer une TVA. Le
 * créateur de la ligne refuse désormais, en nommant la catégorie à corriger. Deux exceptions, voulues :
 * le rejeu hors ligne (la vente a déjà eu lieu, la ligne reste sans taux) et une vraie ligne à 0 %.
 */
final class LineWithoutVatRefusalTest extends VenteApiTestCase
{
    /** Correspondance absente, puis invalide (taux inactif) : 422 qui nomme la catégorie, aucune ligne écrite. Témoin : rétablie, la ligne passe. */
    public function testTheCounterRefusesALineWithoutRateAndNamesTheCategory(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $vente = $this->creerVente($client, $entete, $this->ouvrirSession($client, $entete)['id']);

        // Relue avant chaque geste : le client vide l'`EntityManager` à chaque requête.
        $categorie = $this->mapping()->getCategorie();
        $this->mapping()->setCategorie(Uuid::v4());
        $this->em()->flush();
        $this->ajouterLigne($client, $entete, $vente['id']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(OffreFixtures::CAT_COMPTABLE, $client->getResponse()->getContent(false), 'Aucune correspondance.');

        $this->mapping()->setCategorie($categorie)->getTauxTva()?->setActif(false);
        $this->em()->flush();
        $this->ajouterLigne($client, $entete, $vente['id']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(OffreFixtures::CAT_COMPTABLE, $client->getResponse()->getContent(false), 'Taux inactif.');
        self::assertSame([], $client->request('GET', '/api/ventes/' . $vente['id'], $entete)->toArray()['lignes'], 'Aucune ligne n\'a été écrite.');

        $this->mapping()->getTauxTva()?->setActif(true);
        $this->em()->flush();
        $this->ajouterLigne($client, $entete, $vente['id']);
        self::assertResponseIsSuccessful('Témoin : la correspondance rétablie, la même ligne passe.');
    }

    /** Une vraie ligne à 0 % (hors champ) n'est pas une ligne sans taux : elle passe, gravée à 0,00. */
    public function testAZeroRateLineIsSoldAtZero(): void
    {
        $horsChamp = $this->em()->getRepository(TauxTva::class)->findOneBy(['libelle' => TauxTva::LIBELLE_HORS_CHAMP]);
        self::assertInstanceOf(TauxTva::class, $horsChamp);
        $this->mapping()->setTauxTva($horsChamp);
        $this->em()->flush();

        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $vente = $this->creerVente($client, $entete, $this->ouvrirSession($client, $entete)['id']);
        $this->ajouterLigne($client, $entete, $vente['id']);
        self::assertResponseIsSuccessful();

        $ligne = $client->request('GET', '/api/ventes/' . $vente['id'], $entete)->toArray()['lignes'][0];
        self::assertSame(['0.00', TauxTva::LIBELLE_HORS_CHAMP], [$ligne['tauxTva'] ?? null, $ligne['tauxTvaLibelle'] ?? null]);
    }

    /** Hors ligne, la vente a déjà eu lieu : elle est enregistrée, et sa ligne reste sans taux (« ventilation incomplète » viendra du ticket). */
    public function testTheOfflineReplayKeepsTheSaleAndItsLineStaysWithoutRate(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $session = $this->ouvrirSession($client, $entete);
        $this->mapping()->getTauxTva()?->setActif(false);
        $this->em()->flush();

        $reponse = $client->request('POST', '/api/synchro/operations', $entete + ['json' => [
            'session' => '/api/session_caisses/' . $session['id'],
            'operations' => [[
                'cleIdempotence' => (string) Uuid::v4(),
                'sequenceLocale' => 1,
                'lignes' => [[
                    'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_ENTREE),
                    'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                    'quantite' => 1,
                ]],
                'paiements' => [['moyen' => 'especes', 'montant' => '7.00']],
            ]],
        ]])->toArray(false);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $reponse['inseres'], 'La vente déjà encaissée hors ligne ne doit pas être perdue.');
        self::assertSame([], $reponse['quarantaine']);

        $vente = $this->em()->getRepository(Vente::class)->find($reponse['inseres'][0]);
        self::assertInstanceOf(Vente::class, $vente);
        $lignes = $vente->getLignes()->toArray();
        self::assertCount(1, $lignes);
        self::assertInstanceOf(LigneVente::class, $lignes[0]);
        self::assertSame([null, null], [$lignes[0]->getTauxTva(), $lignes[0]->getTauxTvaLibelle()], 'Jamais 0 % en silence : la ligne reste sans taux.');
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
    }

    private function mapping(): MappingComptable
    {
        $mapping = $this->em()->getRepository(MappingComptable::class)->findOneBy([]);
        self::assertInstanceOf(MappingComptable::class, $mapping, 'La correspondance de ComptaFixtures manque.');

        return $mapping;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
