<?php

declare(strict_types=1);

namespace App\Tests\Musee\Api;

use App\Musee\Entity\AllocationQuotaOTA;
use App\Musee\Entity\PartenaireOTA;
use App\Musee\Entity\ReservationOTA;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\StatutReservation;
use App\Tests\Musee\MuseeApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Distribution OTA — inventaire contrôlé & reversements (US-MUSEE-07, RG-MUS-04, CA-7) et sur-vente
 * OTA — priorité au 1ᵉʳ confirmé & récupération du no-show (US-MUSEE-08, décision actée, CA-8).
 */
final class OtaTest extends MuseeApiTestCase
{
    public function testCa7VenteOtaDecrementeMemeInventaireQueVenteDirecteEtAlimenteReversement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $allocation = $em->getRepository(AllocationQuotaOTA::class)->findOneBy([]);
        self::assertInstanceOf(AllocationQuotaOTA::class, $allocation);
        $idAllocation = (string) $allocation->getId();
        $creneau = $allocation->getCreneau();
        self::assertNotNull($creneau);
        $capaciteInitiale = $creneau->getCapacite();

        $idBeneficiaire = $this->idBeneficiairePayeur();

        $client->request('POST', '/api/musee/reservations-ota', $entete + [
            'json' => [
                'allocation' => '/api/musee_allocation_quota_o_t_as/' . $idAllocation,
                'beneficiaire' => '/api/beneficiaires/' . $idBeneficiaire,
            ],
        ]);
        self::assertResponseIsSuccessful();
        $resaOta = $client->getResponse()->toArray();
        self::assertSame('confirmee', $resaOta['statutOta']);

        $client->request('GET', '/api/musee_allocation_quota_o_t_as/' . $idAllocation, $entete);
        self::assertSame(1, $client->getResponse()->toArray()['quotaConsomme'], 'CA-7 : quotaConsomme incrémenté.');

        // Même inventaire réel que la vente directe : la Reservation générique compte dans la jauge
        // du créneau (JaugeCreneauGuard), pas un compteur OTA séparé.
        $em->clear();
        $creneauRafraichi = $em->getRepository(\App\Reservation\Entity\Creneau::class)->find($creneau->getId());
        self::assertNotNull($creneauRafraichi);
        self::assertSame($capaciteInitiale, $creneauRafraichi->getCapacite(), 'La capacité du créneau reste inchangée (partagée, pas de stock séparé).');
        $reservationsConfirmees = $em->getRepository(Reservation::class)->createQueryBuilder('r')
            ->select('COUNT(r.id)')->andWhere('r.creneau = :c')->andWhere('r.statut = :s')
            ->setParameter('c', $creneau->getId(), 'uuid')->setParameter('s', StatutReservation::Confirmee->value)
            ->getQuery()->getSingleScalarResult();
        self::assertSame('1', (string) $reservationsConfirmees, 'CA-7 : la vente OTA a créé une Reservation dans le même inventaire.');

        // Génère un reversement (tarif net + commission) sur la période courante.
        $partenaire = $em->getRepository(PartenaireOTA::class)->find($allocation->getPartenaire()?->getId());
        self::assertInstanceOf(PartenaireOTA::class, $partenaire);
        $client->request('POST', '/api/musee/reversements/generer', $entete + [
            'json' => [
                'partenaire' => '/api/musee_partenaire_o_t_as/' . (string) $partenaire->getId(),
                'periodeDebut' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d'),
                'periodeFin' => (new \DateTimeImmutable('+1 day'))->format('Y-m-d'),
            ],
        ]);
        self::assertResponseIsSuccessful();
        $reversement = $client->getResponse()->toArray();
        self::assertSame('a_verser', $reversement['statut']);
        self::assertGreaterThan(0.0, (float) $reversement['montant'], 'CA-7 : montant calculé (tarif net + commission).');

        $client->request('POST', '/api/musee/reversements/' . basename((string) $reversement['@id']) . '/marquer-verse', $entete);
        self::assertResponseIsSuccessful();
        self::assertSame('verse', $client->getResponse()->toArray()['statut']);
    }

    public function testCa7AllocationEpuiseeRefuseNouvelleVenteOta(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $allocation = $em->getRepository(AllocationQuotaOTA::class)->findOneBy([]);
        self::assertInstanceOf(AllocationQuotaOTA::class, $allocation);
        $allocation->setQuotaAlloue(0);
        $em->flush();

        $client->request('POST', '/api/musee/reservations-ota', $entete + [
            'json' => [
                'allocation' => '/api/musee_allocation_quota_o_t_as/' . (string) $allocation->getId(),
                'beneficiaire' => '/api/beneficiaires/' . $this->idBeneficiairePayeur(),
            ],
        ]);
        self::assertResponseStatusCodeSame(409, 'RG-MUS-04 : anti sur-vente, allocation épuisée.');
    }

    public function testCa8PrioriteAu1erConfirmeEtStatutRefuseeConflitPourLeSecond(): void
    {
        // Unitaire fonctionnel via le service applicatif (comportement CA-8), couvert aussi en Unit.
        [, $entete] = $this->adminSurA();
        unset($entete);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $allocation = $em->getRepository(AllocationQuotaOTA::class)->findOneBy([]);
        self::assertInstanceOf(AllocationQuotaOTA::class, $allocation);
        $creneau = $allocation->getCreneau();
        self::assertNotNull($creneau);
        $beneficiaire = $em->getRepository(\App\Crm\Entity\Beneficiaire::class)->find($this->idBeneficiairePayeur());
        self::assertNotNull($beneficiaire);

        $premier = new Reservation();
        $premier->setCreneau($creneau)->setOrganisateur($beneficiaire)->setEtablissement($allocation->getEtablissement())
            ->setModeDecompte(\App\Reservation\Enum\ModeDecompteReservation::VenteUnite)->setMontantDu('8.00');
        $em->persist($premier);
        $resaOta1 = (new ReservationOTA())->setAllocation($allocation)->setReservationRattachee($premier)
            ->setHorodatageConfirmation(new \DateTimeImmutable('-10 seconds'));
        $em->persist($resaOta1);

        $second = new Reservation();
        $second->setCreneau($creneau)->setOrganisateur($beneficiaire)->setEtablissement($allocation->getEtablissement())
            ->setModeDecompte(\App\Reservation\Enum\ModeDecompteReservation::VenteUnite)->setMontantDu('8.00');
        $em->persist($second);
        $resaOta2 = (new ReservationOTA())->setAllocation($allocation)->setReservationRattachee($second)
            ->setHorodatageConfirmation(new \DateTimeImmutable());
        $em->persist($resaOta2);
        $em->flush();

        /** @var \App\Musee\Service\PrioriteOtaResolver $resolver */
        $resolver = static::getContainer()->get(\App\Musee\Service\PrioriteOtaResolver::class);
        $resolver->arbitrer([$resaOta2, $resaOta1]);

        self::assertSame(\App\Musee\Enum\StatutReservationOTA::Confirmee, $resaOta1->getStatutOta(), 'CA-8 : priorité au 1ᵉʳ confirmé.');
        self::assertSame(\App\Musee\Enum\StatutReservationOTA::RefuseeConflit, $resaOta2->getStatutOta(), 'CA-8 : le second est refusé côté OTA.');
    }

    public function testCa8RecuperationQuotaNoShowOta(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $allocation = $em->getRepository(AllocationQuotaOTA::class)->findOneBy([]);
        self::assertInstanceOf(AllocationQuotaOTA::class, $allocation);

        $client->request('POST', '/api/musee/reservations-ota', $entete + [
            'json' => [
                'allocation' => '/api/musee_allocation_quota_o_t_as/' . (string) $allocation->getId(),
                'beneficiaire' => '/api/beneficiaires/' . $this->idBeneficiairePayeur(),
            ],
        ]);
        self::assertResponseIsSuccessful();
        $resaOta = $client->getResponse()->toArray();
        $idResaOta = basename((string) $resaOta['@id']);

        $em->clear();
        $allocationApres = $em->getRepository(AllocationQuotaOTA::class)->find($allocation->getId());
        self::assertNotNull($allocationApres);
        self::assertSame(1, $allocationApres->getQuotaConsomme());

        $reservationOta = $em->getRepository(ReservationOTA::class)->find($idResaOta);
        self::assertInstanceOf(ReservationOTA::class, $reservationOta);
        $reservation = $reservationOta->getReservationRattachee();
        self::assertNotNull($reservation);

        // Bascule en no-show (moteur générique Réservation, non modifié) -> le listener additif
        // Musée récupère le quota OTA (CA-8, décision structurante n°5 du plan).
        $reservation->setStatut(StatutReservation::NoShowFacture);
        $em->flush();
        $em->clear();

        $allocationApresNoShow = $em->getRepository(AllocationQuotaOTA::class)->find($allocation->getId());
        self::assertNotNull($allocationApresNoShow);
        self::assertSame(0, $allocationApresNoShow->getQuotaConsomme(), 'CA-8 : le quota du no-show OTA est récupéré et remis à disposition.');

        $reservationOtaApres = $em->getRepository(ReservationOTA::class)->find($idResaOta);
        self::assertInstanceOf(ReservationOTA::class, $reservationOtaApres);
        self::assertSame(\App\Musee\Enum\StatutReservationOTA::RecupereeNoShow, $reservationOtaApres->getStatutOta());
    }
}
