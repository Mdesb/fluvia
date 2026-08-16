<?php

declare(strict_types=1);

namespace App\Tests\Musee\Api;

use App\Musee\Entity\Exposition;
use App\Reservation\Entity\Creneau;
use App\Tests\Musee\MuseeApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Timed-entry & jauge par créneau (US-MUSEE-01, RG-MUS-01, CA-1) — [RÉUTILISE SOCLE] : le musée
 * n'ajoute aucune table de créneau/jauge, il paramètre le module générique `App\Reservation`.
 * Fenêtre exposition (US-MUSEE-09, RG-MUS-06, CA-9) — `FenetreExpositionGuard` (additif, aucune
 * modification de `App\Reservation`).
 */
final class TimedEntryTest extends MuseeApiTestCase
{
    public function testCa1JaugeCreneauDecrementeeAQuotaNulCreneauComplet(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idCreneau = $this->idCreneauMatin();

        // Le créneau démo a une capacité de 30 (fixture) : on la réduit à 1 pour tester la complétude
        // sans créer 30 réservations, en réutilisant l'API générique de créneau (PATCH).
        $client->request('PATCH', '/api/reservation/creneaux/' . $idCreneau, $this->entetePatch($entete) + [
            'json' => ['capacite' => 1],
        ]);
        self::assertResponseIsSuccessful();

        $idBeneficiaire1 = $this->idBeneficiairePayeur();

        // Première réservation : accepte (quota 1 -> 0).
        $client->request('POST', '/api/reservation/reservations', $entete + [
            'json' => [
                'creneau' => '/api/reservation_creneaus/' . $idCreneau,
                'organisateur' => '/api/beneficiaires/' . $idBeneficiaire1,
            ],
        ]);
        self::assertResponseIsSuccessful();

        // Seconde réservation sur le même créneau : quota atteint zéro -> refus (CA-1).
        $client->request('POST', '/api/reservation/reservations', $entete + [
            'json' => [
                'creneau' => '/api/reservation_creneaus/' . $idCreneau,
                'organisateur' => '/api/beneficiaires/' . $idBeneficiaire1,
            ],
        ]);
        self::assertResponseStatusCodeSame(409, 'CA-1 : créneau à quota nul, non sélectionnable.');
    }

    public function testCa9CreneauHorsPeriodeExpositionRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idExpo = $this->idExposition();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $expo = $em->getRepository(Exposition::class)->find($idExpo);
        self::assertInstanceOf(Exposition::class, $expo);
        $ressourceEntree = $expo->getRessourceEntree();
        self::assertNotNull($ressourceEntree);

        $horsPeriode = $expo->getDateFin()?->modify('+1 month');
        self::assertNotNull($horsPeriode);

        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => '/api/reservation_ressources/' . (string) $ressourceEntree->getId(),
                'debut' => $horsPeriode->setTime(10, 0)->format(DATE_ATOM),
                'fin' => $horsPeriode->setTime(11, 0)->format(DATE_ATOM),
                'capacite' => 10,
            ],
        ]);
        self::assertResponseStatusCodeSame(422, 'CA-9/RG-MUS-06 : aucun créneau hors période exposition.');
    }

    public function testCa9CreneauDansLaPeriodeAccepte(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idExpo = $this->idExposition();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $expo = $em->getRepository(Exposition::class)->find($idExpo);
        self::assertInstanceOf(Exposition::class, $expo);
        $ressourceEntree = $expo->getRessourceEntree();
        self::assertNotNull($ressourceEntree);

        $dansPeriode = (new \DateTimeImmutable('next wednesday'))->setTime(10, 0);

        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => '/api/reservation_ressources/' . (string) $ressourceEntree->getId(),
                'debut' => $dansPeriode->format(DATE_ATOM),
                'fin' => $dansPeriode->modify('+1 hour')->format(DATE_ATOM),
                'capacite' => 10,
            ],
        ]);
        self::assertResponseIsSuccessful();
    }
}
