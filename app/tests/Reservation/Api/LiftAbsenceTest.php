<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Api;

use App\Audit\Entity\EntreeAudit;
use App\DataFixtures\SocleFixtures;
use App\Reporting\Enum\GranulariteMesure;
use App\Reporting\Projection\ProjectionReservationInterface;
use App\Reporting\ValueObject\Periode;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\StatutFacturationNoShow;
use App\Reservation\Enum\StatutReservation;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Reservation\ReservationApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * LEVER UNE ABSENCE (07/10/2026) — `no_show_facture` → `terminee_sans_constat`.
 *
 * La bascule automatique, interdite par D95, a tourné en préprod : dix absences constatées à tort,
 * exonérées ensuite, mais restées absences — rien ne sortait une réservation de `no_show_facture`, et
 * le reporting les comptait. Le geste existe désormais, et il ne doit jamais servir à ne pas facturer :
 * droit dédié, motif obligatoire, et seulement une fois la facturation d'absence exonérée.
 */
final class LiftAbsenceTest extends ReservationApiTestCase
{
    public function testAWaivedAbsenceIsLiftedAndTraced(): void
    {
        [$client, $entete, $idA] = $this->adminSurA();
        [$idReservation, $idFacturation] = $this->pastBooking(SocleFixtures::ETAB_A_NOM, StatutReservation::NoShowFacture, StatutFacturationNoShow::AFacturer);

        // La séquence réelle : on exonère d'abord, puis on lève l'absence.
        $client->request('POST', '/api/reservation/facturations-no-show/' . $idFacturation . '/exonerer', $entete + ['json' => ['motif' => 'Bascule interdite par D95.']]);
        self::assertResponseIsSuccessful();
        $reponse = $this->lift($client, $entete, $idReservation, ['motif' => 'Absence constatée à tort par la bascule (D95).']);

        self::assertSame(201, $reponse->getStatusCode(), $reponse->getContent(false));
        self::assertSame('terminee_sans_constat', $reponse->toArray()['statut']);
        self::assertSame('terminee_sans_constat', $this->statut($idReservation));

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $trace = null;
        foreach ($em->getRepository(EntreeAudit::class)->findBy(['cibleType' => Reservation::class, 'cibleId' => $idReservation, 'action' => 'modification']) as $entree) {
            $trace = ($entree->getValeurApres()['statut'] ?? null) === 'terminee_sans_constat' ? $entree : $trace;
        }
        self::assertNotNull($trace, 'La levée doit laisser une entrée d’audit.');
        self::assertSame('no_show_facture', $trace->getValeurAvant()['statut'] ?? null);
        self::assertSame('Absence constatée à tort par la bascule (D95).', $trace->getValeurApres()['absenceLiftReason'] ?? null);
        self::assertSame(SocleFixtures::ADMIN_EMAIL, $trace->getAuteur());
        self::assertSame($idA, (string) $trace->getEtablissement());
    }

    public function testAnAbsenceWithoutBillingCanBeLifted(): void
    {
        [$client, $entete] = $this->adminSurA();
        [$idReservation] = $this->pastBooking(SocleFixtures::ETAB_A_NOM, StatutReservation::NoShowFacture);

        self::assertSame(201, $this->lift($client, $entete, $idReservation, ['motif' => 'Aucune règle, rien à facturer.'])->getStatusCode());
        self::assertSame('terminee_sans_constat', $this->statut($idReservation));
    }

    public function testLiftingWithoutReasonIsRefused(): void
    {
        [$client, $entete] = $this->adminSurA();
        [$idReservation] = $this->pastBooking(SocleFixtures::ETAB_A_NOM, StatutReservation::NoShowFacture, StatutFacturationNoShow::Exoneree);

        foreach ([[], ['motif' => '   '], ['motif' => ['tableau']]] as $corps) {
            self::assertSame(422, $this->lift($client, $entete, $idReservation, $corps)->getStatusCode(), json_encode($corps));
        }
        self::assertSame('no_show_facture', $this->statut($idReservation));
    }

    /** Lever l'absence ne remplace pas l'exonération : tant que la facturation n'est pas exonérée, refus. */
    public function testLiftingIsRefusedWhileTheBillingIsNotWaived(): void
    {
        [$client, $entete] = $this->adminSurA();
        foreach ([StatutFacturationNoShow::AFacturer, StatutFacturationNoShow::Facturee, StatutFacturationNoShow::Contestee] as $billing) {
            [$idReservation] = $this->pastBooking(SocleFixtures::ETAB_A_NOM, StatutReservation::NoShowFacture, $billing);

            self::assertSame(409, $this->lift($client, $entete, $idReservation, ['motif' => 'Ne pas facturer.'])->getStatusCode(), $billing->value);
            self::assertSame('no_show_facture', $this->statut($idReservation), $billing->value);
        }
    }

    public function testOnlyABilledAbsenceCanBeLifted(): void
    {
        [$client, $entete] = $this->adminSurA();
        foreach ([StatutReservation::Confirmee, StatutReservation::Honoree, StatutReservation::AnnuleeTardiveFacturee] as $statut) {
            [$idReservation] = $this->pastBooking(SocleFixtures::ETAB_A_NOM, $statut, StatutFacturationNoShow::Exoneree);

            self::assertSame(409, $this->lift($client, $entete, $idReservation, ['motif' => 'Essai.'])->getStatusCode(), $statut->value);
            self::assertSame($statut->value, $this->statut($idReservation));
        }
    }

    /** Le caissier ou l'agent d'accueil sans le droit dédié ne lève rien, même avec un motif. */
    public function testLiftingRequiresTheDedicatedPermission(): void
    {
        [$client, $entete] = $this->agentSurA();
        [$idReservation] = $this->pastBooking(SocleFixtures::ETAB_A_NOM, StatutReservation::NoShowFacture, StatutFacturationNoShow::Exoneree);

        self::assertSame(403, $this->lift($client, $entete, $idReservation, ['motif' => 'Pour ne pas facturer.'])->getStatusCode());
        self::assertSame('no_show_facture', $this->statut($idReservation));
    }

    /** L'administratrice est affectée à A et B : regardant A, la réservation de B lui est introuvable. */
    public function testABookingOfAnotherEstablishmentIsNotFound(): void
    {
        [$client, $entete] = $this->adminSurA();
        [$idReservation] = $this->pastBooking(SocleFixtures::ETAB_B_NOM, StatutReservation::NoShowFacture, StatutFacturationNoShow::Exoneree);

        self::assertSame(404, $this->lift($client, $entete, $idReservation, ['motif' => 'Hors périmètre.'])->getStatusCode());
        self::assertSame('no_show_facture', $this->statut($idReservation));

        // Témoin : regardant B, elle la lève — sans lui, le 404 pourrait tenir à une route absente.
        $entete['headers'][ContexteEtablissement::HEADER] = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);
        self::assertSame(201, $this->lift($client, $entete, $idReservation, ['motif' => 'Sur son établissement.'])->getStatusCode());
    }

    public function testReportingNoLongerCountsALiftedAbsence(): void
    {
        [$client, $entete, $idA] = $this->adminSurA();
        [$levee] = $this->pastBooking(SocleFixtures::ETAB_A_NOM, StatutReservation::NoShowFacture, StatutFacturationNoShow::Exoneree);
        $this->pastBooking(SocleFixtures::ETAB_A_NOM, StatutReservation::NoShowFacture, StatutFacturationNoShow::Exoneree);
        $periode = new Periode(new \DateTimeImmutable('-1 day'), new \DateTimeImmutable('+1 day'), GranulariteMesure::Jour);
        /** @var ProjectionReservationInterface $projection */
        $projection = static::getContainer()->get(ProjectionReservationInterface::class);
        self::assertSame(2, $projection->noShow(Uuid::fromString($idA), $periode), 'Témoin : les deux absences comptent avant la levée.');

        self::assertSame(201, $this->lift($client, $entete, $levee, ['motif' => 'Absence constatée à tort.'])->getStatusCode());

        $projection = static::getContainer()->get(ProjectionReservationInterface::class);
        self::assertSame(1, $projection->noShow(Uuid::fromString($idA), $periode), 'Une absence levée n’est plus une absence.');
    }

    /**
     * @param array<string, mixed> $entete
     * @param array<string, mixed> $corps
     */
    private function lift(object $client, array $entete, string $idReservation, array $corps): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        return $client->request('POST', '/api/reservation/reservations/' . $idReservation . '/lever-absence', $entete + ['json' => $corps]);
    }

    private function statut(string $idReservation): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();

        return $em->find(Reservation::class, $idReservation)->getStatut()->value;
    }
}
