<?php

declare(strict_types=1);

namespace App\Tests\Musee\Api;

use App\Musee\Entity\AllocationQuotaOTA;
use App\Tests\Musee\MuseeApiTestCase;
use App\Tests\Reservation\ConcurrentSlotWriter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Une vente OTA musée pèse sur la jauge globale de la ressource (RG-M5-08).
 *
 * Elle créait une `Reservation` sans incrémenter `Ressource.occupationCourante`, alors que toutes les
 * annulations le décrémentent : annuler cette réservation rendait au compteur une unité que personne
 * n'y avait posée, et le compteur dérivait vers le bas — vers la surréservation.
 */
final class OtaOccupancyTest extends MuseeApiTestCase
{
    use ConcurrentSlotWriter;

    public function testAnOtaSaleCountsOnTheResourceGauge(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $allocation = $em->getRepository(AllocationQuotaOTA::class)->findOneBy([]);
        self::assertInstanceOf(AllocationQuotaOTA::class, $allocation);
        $allocation->setQuotaAlloue(100)->setQuotaConsomme(0);
        $em->flush();
        $ressource = $allocation->getCreneau()?->getRessource();
        self::assertNotNull($ressource);
        $idRessource = (string) $ressource->getId();

        $avant = $this->occupationInDatabase($idRessource);

        $client->request('POST', '/api/musee/reservations-ota', $entete + [
            'json' => [
                'allocation' => '/api/musee_allocation_quota_otas/' . $allocation->getId(),
                'beneficiaire' => '/api/beneficiaires/' . $this->idBeneficiairePayeur(),
            ],
        ]);
        self::assertResponseIsSuccessful();

        self::assertSame(
            $avant + 1,
            $this->occupationInDatabase($idRessource),
            'La place vendue par l\'OTA compte sur la jauge globale, comme celle d\'une réservation ordinaire.',
        );
    }
}
