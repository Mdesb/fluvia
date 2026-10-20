<?php

declare(strict_types=1);

namespace App\Tests\Musee\Api;

use App\DataFixtures\SocleFixtures;
use App\Musee\Entity\AllocationQuotaOTA;
use App\Musee\Entity\ReservationOTA;
use App\Musee\Enum\StatutReservationOTA;
use App\Reservation\Enum\StatutReservation;
use App\Tests\Musee\MuseeApiTestCase;
use App\Tests\Platform\ModuleSwitch;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Recentrage, PR 2 (d) — l'écouteur Doctrine du Musée ne touche plus au quota OTA d'un établissement
 * où le module est éteint.
 *
 * Une réservation OTA est vendue module allumé, puis le module est éteint et la réservation du cœur
 * bascule en no-show (le cœur, lui, continue de vivre). L'écouteur ne doit ni rendre le quota ni
 * changer le statut OTA. Le témoin : `OtaTest::testCa8RecuperationQuotaNoShowOta`, même scénario
 * module allumé, où le quota est rendu.
 */
final class SwitchedOffOtaNoShowTest extends MuseeApiTestCase
{
    use ModuleSwitch;

    public function testANoShowDoesNotTouchTheOtaQuotaWhenMuseeIsOff(): void
    {
        [$client, $headers] = $this->adminSurA();
        $client->disableReboot();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $allocation = $em->getRepository(AllocationQuotaOTA::class)->findOneBy([]);
        self::assertInstanceOf(AllocationQuotaOTA::class, $allocation);

        $client->request('POST', '/api/musee/reservations-ota', $headers + [
            'json' => [
                'allocation' => '/api/musee_allocation_quota_otas/' . (string) $allocation->getId(),
                'beneficiaire' => '/api/beneficiaires/' . $this->idBeneficiairePayeur(),
            ],
        ]);
        self::assertResponseIsSuccessful();
        $otaId = basename((string) $client->getResponse()->toArray()['@id']);

        $this->switchModule('musee', false, SocleFixtures::ETAB_A_NOM);

        $em->clear();
        $ota = $em->getRepository(ReservationOTA::class)->find($otaId);
        self::assertInstanceOf(ReservationOTA::class, $ota);
        $ota->getReservationRattachee()?->setStatut(StatutReservation::NoShowFacture);
        $em->flush();
        $em->clear();

        self::assertSame(1, $em->getRepository(AllocationQuotaOTA::class)->find($allocation->getId())?->getQuotaConsomme());
        self::assertNotSame(StatutReservationOTA::RecupereeNoShow, $em->getRepository(ReservationOTA::class)->find($otaId)?->getStatutOta());
    }
}
