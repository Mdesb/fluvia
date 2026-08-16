<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Boutique\DataFixtures\BoutiqueFixtures;
use App\Boutique\Entity\AllocationQuotaOTA;
use App\Boutique\Entity\PartenaireOTA;
use App\Boutique\Entity\Vitrine;
use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client;
use App\Offre\Entity\Produit;
use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\Reservation;
use App\Tests\Boutique\BoutiqueApiTestCase;

/**
 * Connecteurs OTA — inventaire partagé, anti sur-vente (US-L8-14, RG-M3-09, CA-19).
 */
final class OtaTest extends BoutiqueApiTestCase
{
    public function testCa19VenteOtaDecrementeLeMemeInventaireQueLaVenteDirecte(): void
    {
        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_TIMED_ENTRY_CODE]);
        $ressourceId = $produit->getChampsPerso()['ressourceId'] ?? null;
        self::assertIsString($ressourceId, 'Produit timed-entry sans ressource associée (fixture).');
        $creneau = $this->em()->getRepository(Creneau::class)->createQueryBuilder('c')
            ->andWhere('c.ressource = :ressource')->setParameter('ressource', $ressourceId, 'uuid')
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
        self::assertInstanceOf(Creneau::class, $creneau);
        $vitrineA = $this->entite(Vitrine::class, ['etablissement' => $this->entite(\App\Organisation\Entity\Etablissement::class, ['nom' => \App\DataFixtures\SocleFixtures::ETAB_A_NOM])]);

        $partenaire = (new PartenaireOTA())->setVitrine($vitrineA)->setNom('Partenaire démo')
            ->setTarifNet('10.00')->setCommission('15.00')->setCodeConnecteur('demo');
        $this->em()->persist($partenaire);
        $allocation = (new AllocationQuotaOTA())->setPartenaire($partenaire)->setCreneau($creneau)->setQuotaAlloue(1);
        $this->em()->persist($allocation);
        $this->em()->flush();
        $allocationId = (string) $allocation->getId();

        $payeur = $this->em()->getRepository(Client::class)->findOneBy(['email' => CrmFixtures::PAYEUR_EMAIL]);
        self::assertInstanceOf(Client::class, $payeur);
        $beneficiaire = $this->em()->getRepository(Beneficiaire::class)->findOneBy(['client' => $payeur]);
        self::assertInstanceOf(Beneficiaire::class, $beneficiaire);

        [$client, $entete] = $this->adminSurA();

        $reponse = $client->request('POST', '/api/boutique/ota/ventes', $entete + [
            'json' => ['allocation' => $allocationId, 'beneficiaire' => (string) $beneficiaire->getId()],
        ]);
        self::assertResponseStatusCodeSame(201);
        $donnees = $reponse->toArray();
        self::assertSame(1, $donnees['quotaConsomme']);

        // Même inventaire réel que la vente directe : une Reservation a été créée sur le créneau.
        $reservations = $this->em()->getRepository(Reservation::class)->findBy(['creneau' => $creneau]);
        self::assertCount(1, $reservations, 'RG-M3-09 : la vente OTA décrémente le même compteur réel.');

        // Quota épuisé (1 alloué, 1 consommé) : une seconde ingestion est refusée (anti sur-vente).
        $client->request('POST', '/api/boutique/ota/ventes', $entete + [
            'json' => ['allocation' => $allocationId, 'beneficiaire' => (string) $beneficiaire->getId()],
        ]);
        self::assertResponseStatusCodeSame(409);

        // ReversementOTA : tarif net + commission (RG-M3-09).
        self::assertSame('11.50', $partenaire->montantParVente());
    }
}
