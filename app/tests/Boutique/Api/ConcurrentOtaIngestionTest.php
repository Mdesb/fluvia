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
use App\DataFixtures\SocleFixtures;
use App\Offre\Entity\Produit;
use App\Organisation\Entity\Etablissement;
use App\Reservation\Entity\Creneau;
use App\Tests\Boutique\BoutiqueApiTestCase;
use App\Tests\Reservation\ConcurrentSlotWriter;

/**
 * Ingestion d'une vente OTA boutique pendant une réservation concurrente du même créneau (RG-M3-09).
 *
 * `IngestionVenteOtaProcessor` contrôlait la jauge puis écrivait, sans transaction : une vente OTA et
 * une réservation simultanées prenaient la même dernière place. La concurrence est jouée par
 * `ConcurrentSlotWriter`.
 */
final class ConcurrentOtaIngestionTest extends BoutiqueApiTestCase
{
    use ConcurrentSlotWriter;

    public function testAnOtaIngestionWaitsForAConcurrentBookingAndSeesTheSlotFull(): void
    {
        [$idAllocation, $idCreneau] = $this->allocationOnTimedEntrySlot();
        $beneficiaire = $this->beneficiairePayeur();
        [$client, $entete] = $this->adminSurA();

        $remplissage = $this->persistFillerReservation($idCreneau, $beneficiaire);
        $concurrent = $this->holdSlotLock($idCreneau, $remplissage, $this->fillerQuantityLeaving($idCreneau, 0));
        $debut = microtime(true);
        $client->request('POST', '/api/boutique/ota/ventes', $entete + [
            'json' => ['allocation' => $idAllocation, 'beneficiaire' => (string) $beneficiaire->getId()],
        ]);
        $attente = microtime(true) - $debut;
        $this->releaseSlotLock($concurrent);

        self::assertResponseStatusCodeSame(409, 'La dernière place a été prise par la réservation concurrente.');
        self::assertStringContainsString('créneau complet', $client->getResponse()->toArray(false)['detail'] ?? '');
        $this->assertWaitedForTheConcurrentWrite($attente);
    }

    public function testAnOtaIngestionTakesThePlaceStillFreeAfterTheWait(): void
    {
        [$idAllocation, $idCreneau] = $this->allocationOnTimedEntrySlot();
        $beneficiaire = $this->beneficiairePayeur();
        [$client, $entete] = $this->adminSurA();

        $remplissage = $this->persistFillerReservation($idCreneau, $beneficiaire);
        $concurrent = $this->holdSlotLock($idCreneau, $remplissage, $this->fillerQuantityLeaving($idCreneau, 1));
        $debut = microtime(true);
        $client->request('POST', '/api/boutique/ota/ventes', $entete + [
            'json' => ['allocation' => $idAllocation, 'beneficiaire' => (string) $beneficiaire->getId()],
        ]);
        $attente = microtime(true) - $debut;
        $this->releaseSlotLock($concurrent);

        self::assertResponseStatusCodeSame(201, 'La place réellement libre doit être vendue.');
        $this->assertWaitedForTheConcurrentWrite($attente);

        // Le créneau est plein : l'ingestion suivante est refusée, sans concurrent cette fois.
        $client->request('POST', '/api/boutique/ota/ventes', $entete + [
            'json' => ['allocation' => $idAllocation, 'beneficiaire' => (string) $beneficiaire->getId()],
        ]);
        self::assertResponseStatusCodeSame(409);
    }

    /** @return array{0: string, 1: string} identifiants de l'allocation et du créneau timed-entry */
    private function allocationOnTimedEntrySlot(): array
    {
        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_TIMED_ENTRY_CODE]);
        $creneau = $this->em()->getRepository(Creneau::class)->createQueryBuilder('c')
            ->join('c.activite', 'a')
            ->andWhere('a.produitTarifReference = :produit')
            ->setParameter('produit', $produit->getId(), 'uuid')
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
        self::assertInstanceOf(Creneau::class, $creneau, 'Produit timed-entry sans activité porteuse (fixture).');
        $vitrineA = $this->entite(Vitrine::class, ['etablissement' => $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM])]);

        $partenaire = (new PartenaireOTA())->setVitrine($vitrineA)->setNom('Partenaire concurrence')
            ->setTarifNet('10.00')->setCommission('15.00')->setCodeConnecteur('demo');
        $this->em()->persist($partenaire);
        // Le quota ne doit pas être la limite atteinte : c'est la jauge du créneau qu'on éprouve.
        $allocation = (new AllocationQuotaOTA())->setPartenaire($partenaire)->setCreneau($creneau)->setQuotaAlloue(100);
        $this->em()->persist($allocation);
        $this->em()->flush();

        return [(string) $allocation->getId(), (string) $creneau->getId()];
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
