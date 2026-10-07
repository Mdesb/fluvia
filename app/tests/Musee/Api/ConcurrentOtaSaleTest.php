<?php

declare(strict_types=1);

namespace App\Tests\Musee\Api;

use App\Crm\Entity\Beneficiaire;
use App\Musee\Entity\AllocationQuotaOTA;
use App\Tests\Musee\MuseeApiTestCase;
use App\Tests\Reservation\ConcurrentSlotWriter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Vente OTA musée pendant une réservation concurrente du même créneau (RG-MUS-04).
 *
 * `CreerReservationOtaProcessor` contrôlait la jauge puis écrivait, sans transaction : une vente OTA
 * et une réservation simultanées prenaient la même dernière place. La concurrence est jouée par
 * `ConcurrentSlotWriter`.
 */
final class ConcurrentOtaSaleTest extends MuseeApiTestCase
{
    use ConcurrentSlotWriter;

    public function testAnOtaSaleWaitsForAConcurrentBookingAndSeesTheSlotFull(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        [$idAllocation, $idCreneau] = $this->allocationWithQuota();
        $beneficiaire = $this->beneficiairePayeur();

        $remplissage = $this->persistFillerReservation($idCreneau, $beneficiaire);
        $concurrent = $this->holdSlotLock($idCreneau, $remplissage, $this->fillerQuantityLeaving($idCreneau, 0));
        $debut = microtime(true);
        $this->vendre($client, $entete, $idAllocation, $beneficiaire);
        $attente = microtime(true) - $debut;
        $this->releaseSlotLock($concurrent);

        self::assertResponseStatusCodeSame(409, 'La dernière place a été prise par la réservation concurrente.');
        self::assertStringContainsString('créneau complet', $client->getResponse()->toArray(false)['detail'] ?? '');
        $this->assertWaitedForTheConcurrentWrite($attente);
    }

    public function testAnOtaSaleTakesThePlaceStillFreeAfterTheWait(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        [$idAllocation, $idCreneau] = $this->allocationWithQuota();
        $beneficiaire = $this->beneficiairePayeur();

        $remplissage = $this->persistFillerReservation($idCreneau, $beneficiaire);
        $concurrent = $this->holdSlotLock($idCreneau, $remplissage, $this->fillerQuantityLeaving($idCreneau, 1));
        $debut = microtime(true);
        $this->vendre($client, $entete, $idAllocation, $beneficiaire);
        $attente = microtime(true) - $debut;
        $this->releaseSlotLock($concurrent);

        self::assertResponseIsSuccessful('La place réellement libre doit être vendue.');
        $this->assertWaitedForTheConcurrentWrite($attente);

        // Le créneau est plein : la vente suivante est refusée, sans concurrent cette fois.
        $this->vendre($client, $entete, $idAllocation, $beneficiaire);
        self::assertResponseStatusCodeSame(409);
    }

    /** @return array{0: string, 1: string} identifiants de l'allocation et de son créneau */
    private function allocationWithQuota(): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $allocation = $em->getRepository(AllocationQuotaOTA::class)->findOneBy([]);
        self::assertInstanceOf(AllocationQuotaOTA::class, $allocation);
        // Le quota ne doit pas être la limite atteinte : c'est la jauge du créneau qu'on éprouve.
        $allocation->setQuotaAlloue(100)->setQuotaConsomme(0);
        $em->flush();
        $creneau = $allocation->getCreneau();
        self::assertNotNull($creneau);

        return [(string) $allocation->getId(), (string) $creneau->getId()];
    }

    private function beneficiairePayeur(): Beneficiaire
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        // `find()` par identifiant, pas un critère `['id' => …]` : sur une entité à identifiant uid, un
        // critère mal typé peut ne rien trouver sans lever.
        $beneficiaire = $em->getRepository(Beneficiaire::class)->find($this->idBeneficiairePayeur());
        self::assertInstanceOf(Beneficiaire::class, $beneficiaire);

        return $beneficiaire;
    }

    /** @param array<string, mixed> $entete */
    private function vendre(object $client, array $entete, string $idAllocation, Beneficiaire $beneficiaire): void
    {
        $client->request('POST', '/api/musee/reservations-ota', $entete + [
            'json' => [
                'allocation' => '/api/musee_allocation_quota_otas/' . $idAllocation,
                'beneficiaire' => '/api/beneficiaires/' . $beneficiaire->getId(),
            ],
        ]);
    }
}
