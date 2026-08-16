<?php

declare(strict_types=1);

namespace App\Musee\Service;

use App\Crm\Entity\Beneficiaire;
use App\Musee\Entity\ContingentGratuite;
use App\Musee\Entity\DossierGroupeScolaire;
use App\Musee\Enum\MotifGratuite;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\ModeDecompteReservation;
use App\Reservation\Service\JaugeCreneauGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Confirmation d'un dossier groupe/scolaire (US-MUSEE-06, RG-MUS-03, CA-6) : génère `effectif +
 * accompagnateurs` `Reservation` (module socle Réservation **réutilisé**) contre `creneauEntree`, au
 * grain d'une réservation par visiteur (décision structurante n°2 du plan Musée) — les entrées
 * gratuites via `AccorderGratuiteHandler` (CA-5), les autres en `vente_unite` **différée** (aucune
 * `Vente` M2 créée tant que `statutPaiement ≠ paye`, RG-MUS-03).
 */
final class ConfirmerDossierGroupeHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JaugeCreneauGuard $jauge,
        private readonly AccorderGratuiteHandler $gratuiteHandler,
    ) {
    }

    public function confirmer(
        DossierGroupeScolaire $dossier,
        Beneficiaire $responsable,
        int $nbGratuitesEleve = 0,
        int $nbGratuitesAccompagnateur = 0,
        ?ContingentGratuite $contingent = null,
    ): DossierGroupeScolaire {
        $creneau = $dossier->getCreneauEntree();
        if ($creneau === null) {
            throw new UnprocessableEntityHttpException('Dossier sans créneau d\'entrée rattaché.');
        }

        $totalGratuites = $nbGratuitesEleve + $nbGratuitesAccompagnateur;
        $total = $dossier->totalPersonnes();
        if ($totalGratuites > $total) {
            throw new UnprocessableEntityHttpException('Le nombre de gratuités dépasse l\'effectif total du dossier.');
        }
        if ($totalGratuites > 0 && $contingent === null) {
            throw new UnprocessableEntityHttpException('Un contingent de gratuités doit être précisé pour accorder des gratuités.');
        }
        if ($contingent !== null && $contingent->placesRestantes() < $totalGratuites) {
            throw new UnprocessableEntityHttpException(AccorderGratuiteHandler::MESSAGE_CONTINGENT_EPUISE);
        }

        if ($this->jauge->placesRestantes($creneau) < $total) {
            throw new ConflictHttpException('RG-MUS-01 : quota de jauge du créneau insuffisant pour l\'effectif du dossier.');
        }

        $nbPayantes = $total - $totalGratuites;
        for ($i = 0; $i < $nbPayantes; ++$i) {
            $reservation = new Reservation();
            $reservation->setCreneau($creneau)
                ->setOrganisateur($responsable)
                ->setEtablissement($dossier->getEtablissement())
                ->setModeDecompte(ModeDecompteReservation::VenteUnite)
                ->setMontantDu('0.00');
            $this->em->persist($reservation);
        }

        for ($i = 0; $i < $nbGratuitesEleve; ++$i) {
            \assert($contingent instanceof ContingentGratuite);
            $this->gratuiteHandler->accorder($dossier, MotifGratuite::Eleve, $contingent, $responsable);
        }
        for ($i = 0; $i < $nbGratuitesAccompagnateur; ++$i) {
            \assert($contingent instanceof ContingentGratuite);
            $this->gratuiteHandler->accorder($dossier, MotifGratuite::Accompagnateur, $contingent, $responsable);
        }

        $this->em->flush();

        return $dossier;
    }
}
