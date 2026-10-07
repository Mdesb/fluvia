<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Boutique\DataFixtures\BoutiqueFixtures;
use App\Boutique\Entity\AllocationQuotaOTA;
use App\Boutique\Entity\PartenaireOTA;
use App\Boutique\Entity\Vitrine;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client;
use App\DataFixtures\SocleFixtures;
use App\Offre\Entity\Produit;
use App\Organisation\Entity\Etablissement;
use App\Reservation\Entity\Creneau;
use App\Tests\Boutique\BoutiqueApiTestCase;
use App\Tests\Reservation\ConcurrentSlotWriter;

/**
 * Les deux chemins boutique pèsent sur la jauge globale de la ressource (RG-M5-08).
 *
 * Ni l'ingestion OTA ni la confirmation de commande n'incrémentaient
 * `Ressource.occupationCourante`, alors que toutes les annulations le décrémentent : annuler une de
 * ces réservations rendait au compteur des unités que personne n'y avait posées.
 */
final class OrderAndOtaOccupancyTest extends BoutiqueApiTestCase
{
    use ConcurrentSlotWriter;

    public function testAnOtaIngestionCountsOnTheResourceGauge(): void
    {
        $creneau = $this->creneauTimedEntry();
        $idRessource = (string) $creneau->getRessource()?->getId();
        $vitrineA = $this->entite(Vitrine::class, ['etablissement' => $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM])]);
        $partenaire = (new PartenaireOTA())->setVitrine($vitrineA)->setNom('Partenaire jauge')
            ->setTarifNet('10.00')->setCommission('15.00')->setCodeConnecteur('demo');
        $this->em()->persist($partenaire);
        $allocation = (new AllocationQuotaOTA())->setPartenaire($partenaire)->setCreneau($creneau)->setQuotaAlloue(10);
        $this->em()->persist($allocation);
        $this->em()->flush();

        $avant = $this->occupationInDatabase($idRessource);
        [$client, $entete] = $this->adminSurA();
        $client->request('POST', '/api/boutique/ota/ventes', $entete + [
            'json' => ['allocation' => (string) $allocation->getId(), 'beneficiaire' => (string) $this->beneficiairePayeur()->getId()],
        ]);
        self::assertResponseStatusCodeSame(201);

        self::assertSame($avant + 1, $this->occupationInDatabase($idRessource), 'La vente OTA compte sur la jauge globale.');
    }

    public function testAConfirmedOrderCountsItsLineQuantityOnTheResourceGauge(): void
    {
        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_TIMED_ENTRY_CODE]);
        $idRessource = (string) $this->creneauTimedEntry()->getRessource()?->getId();
        $avant = $this->occupationInDatabase($idRessource);

        [$client, $panierId, $jeton] = $this->ouvrirPanierInviteA();
        $entete = [PanierProprietaireGuard::HEADER => $jeton];
        $creneaux = $client->request('GET', '/api/boutique/produits/' . $produit->getId() . '/creneaux')->toArray();
        $idCreneau = (string) $creneaux['creneaux'][0]['creneau'];

        $panier = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/lignes', [
            'headers' => $entete,
            'json' => ['produit' => (string) $produit->getId(), 'creneau' => $idCreneau, 'quantite' => 2],
        ])->toArray();
        $ligneId = (string) $panier['lignes'][0]['id'];

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/identifier', [
            'headers' => $entete,
            'json' => ['mode' => 'invite', 'email' => 'invite.jauge@example.test'],
        ]);
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/consentement', ['headers' => $entete, 'json' => ['mentionVersion' => 'mention-test']]);
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/beneficiaires', [
            'headers' => $entete,
            'json' => ['lignes' => [['ligneId' => $ligneId, 'beneficiaireSimple' => ['nom' => 'Durand', 'prenom' => 'Sam', 'dateNaissance' => '1990-01-01']]]],
        ]);
        $paiement = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/payer', ['headers' => $entete])->toArray();
        self::assertResponseIsSuccessful();

        $confirmation = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/retour-paiement', [
            'headers' => $entete,
            'json' => [
                'referenceTransaction' => $paiement['referenceTransaction'],
                'recu' => $paiement['simulation']['accepte'],
            ],
        ])->toArray(false);
        self::assertSame('confirme', $confirmation['statut'] ?? null, 'Commande : ' . json_encode($confirmation));

        // Deux billets, deux places : le compteur porte la quantité de la ligne, pas une unité.
        self::assertSame($avant + 2, $this->occupationInDatabase($idRessource));
    }

    private function creneauTimedEntry(): Creneau
    {
        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_TIMED_ENTRY_CODE]);
        $creneau = $this->em()->getRepository(Creneau::class)->createQueryBuilder('c')
            ->join('c.activite', 'a')
            ->andWhere('a.produitTarifReference = :produit')
            ->setParameter('produit', $produit->getId(), 'uuid')
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
        self::assertInstanceOf(Creneau::class, $creneau, 'Produit timed-entry sans activité porteuse (fixture).');

        return $creneau;
    }

    private function beneficiairePayeur(): Beneficiaire
    {
        $payeur = $this->em()->getRepository(Client::class)->findOneBy(['email' => CrmFixtures::PAYEUR_EMAIL]);
        self::assertInstanceOf(Client::class, $payeur);
        $beneficiaire = $this->em()->getRepository(Beneficiaire::class)->findOneBy(['client' => $payeur]);
        self::assertInstanceOf(Beneficiaire::class, $beneficiaire);

        return $beneficiaire;
    }
}
