<?php

declare(strict_types=1);

namespace App\Musee\Service;

use App\Crm\Entity\Beneficiaire;
use App\Musee\Entity\ContingentGratuite;
use App\Musee\Entity\DossierGroupeScolaire;
use App\Musee\Entity\Gratuite;
use App\Musee\Enum\MotifGratuite;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\ModeDecompteReservation;
use App\Reservation\Service\JaugeCreneauGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Octroi d'une gratuité scolaire (RG-MUS-03, décision actée « Gratuités scolaires », CA-5) :
 * décrémente **simultanément** le quota de jauge du créneau (une `Reservation.modeDecompte=gratuit`,
 * module socle Réservation **réutilisé**, décision structurante n°2 du plan Musée) et le
 * `ContingentGratuite` dédié. Refuse explicitement si le contingent est épuisé, **même si** la jauge
 * du créneau reste disponible.
 */
final class AccorderGratuiteHandler
{
    public const MESSAGE_CONTINGENT_EPUISE = 'RG-MUS-03/CA-5 : le contingent de gratuités dédié est épuisé (le quota de jauge du créneau reste, lui, éventuellement disponible).';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JaugeCreneauGuard $jauge,
    ) {
    }

    public function accorder(DossierGroupeScolaire $dossier, MotifGratuite $motif, ContingentGratuite $contingent, Beneficiaire $responsable): Gratuite
    {
        if ($contingent->getQuotaConsomme() >= $contingent->getQuotaGratuitesDedie()) {
            throw new UnprocessableEntityHttpException(self::MESSAGE_CONTINGENT_EPUISE);
        }

        $creneau = $dossier->getCreneauEntree();
        if ($creneau === null) {
            throw new UnprocessableEntityHttpException('Dossier sans créneau d\'entrée rattaché.');
        }
        if ($this->jauge->estComplet($creneau)) {
            throw new ConflictHttpException('RG-MUS-01 : quota de jauge du créneau atteint.');
        }

        $reservation = new Reservation();
        $reservation->setCreneau($creneau)
            ->setOrganisateur($responsable)
            ->setEtablissement($dossier->getEtablissement())
            ->setModeDecompte(ModeDecompteReservation::Gratuit)
            ->setMontantDu('0.00');
        $this->em->persist($reservation);

        $contingent->setQuotaConsomme($contingent->getQuotaConsomme() + 1);

        $gratuite = new Gratuite();
        $gratuite->setDossier($dossier)
            ->setMotif($motif)
            ->setContingent($contingent)
            ->setReservationRattachee($reservation);
        $this->em->persist($gratuite);

        return $gratuite;
    }
}
