<?php

declare(strict_types=1);

namespace App\Tests\Audit\Api;

use App\Audit\Entity\EntreeAudit;
use App\DataFixtures\SocleFixtures;
use App\Reservation\Entity\FacturationNoShow;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\StatutFacturationNoShow;
use App\Reservation\Enum\StatutReservation;
use App\Tests\Reservation\ReservationApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * L'EXONÉRATION D'UNE ABSENCE ET LE STATUT D'UNE RÉSERVATION SONT AU JOURNAL (07/10/2026).
 *
 * La spec des réservations (§8) dit que le journal d'audit porte les exonérations et les bascules en
 * absence. Ni `FacturationNoShow` ni `Reservation` n'étaient auditées : les dix exonérations de
 * préprod du 07/10 n'ont laissé aucune trace (qui, quand, avant, après). L'entrée doit aussi porter
 * l'établissement de la réservation, sinon elle n'est lue que par l'éditeur (#260).
 */
final class ReservationAuditTest extends ReservationApiTestCase
{
    public function testWaiverIsAuditedWithBeforeAfterAuthorAndEstablishment(): void
    {
        [$client, $entete, $idA] = $this->adminSurA();
        [, $idFacturation] = $this->pastBooking(SocleFixtures::ETAB_A_NOM, StatutReservation::NoShowFacture, StatutFacturationNoShow::AFacturer);

        $client->request('POST', '/api/reservation/facturations-no-show/' . $idFacturation . '/exonerer', $entete + [
            'json' => ['motif' => 'Bascule interdite par D95.'],
        ]);
        self::assertResponseIsSuccessful();

        $entree = $this->modification(FacturationNoShow::class, (string) $idFacturation);
        self::assertSame('a_facturer', $entree->getValeurAvant()['statut'] ?? null);
        self::assertSame('exoneree', $entree->getValeurApres()['statut'] ?? null);
        self::assertSame('Bascule interdite par D95.', $entree->getValeurApres()['motifExoneration'] ?? null);
        self::assertSame(SocleFixtures::ADMIN_EMAIL, $entree->getAuteur());
        self::assertSame($idA, (string) $entree->getEtablissement(), 'L’entrée doit porter l’établissement de la réservation.');
    }

    public function testBookingStatusChangeIsAuditedWithBeforeAfterAuthorAndEstablishment(): void
    {
        [$client, $entete, $idA] = $this->adminSurA();
        [$idReservation] = $this->pastBooking(SocleFixtures::ETAB_A_NOM, StatutReservation::Confirmee);

        $client->request('POST', '/api/reservation/reservations/' . $idReservation . '/annuler', $entete);
        self::assertResponseIsSuccessful();

        $entree = $this->modification(Reservation::class, $idReservation);
        self::assertSame('confirmee', $entree->getValeurAvant()['statut'] ?? null);
        self::assertSame('annulee_libre', $entree->getValeurApres()['statut'] ?? null);
        self::assertSame(SocleFixtures::ADMIN_EMAIL, $entree->getAuteur());
        self::assertSame($idA, (string) $entree->getEtablissement(), 'L’entrée doit porter l’établissement de la réservation.');
    }

    /** L'entrée « modification » qui porte le changement de statut. */
    private function modification(string $classe, string $id): EntreeAudit
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        foreach ($em->getRepository(EntreeAudit::class)->findBy(['cibleType' => $classe, 'cibleId' => $id, 'action' => 'modification']) as $entree) {
            if (isset($entree->getValeurApres()['statut'])) {
                return $entree;
            }
        }
        self::fail(sprintf('Aucune entrée d’audit ne porte le changement de statut de %s %s.', $classe, $id));
    }
}
