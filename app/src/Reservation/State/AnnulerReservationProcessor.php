<?php

declare(strict_types=1);

namespace App\Reservation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\StatutReservation;
use App\Reservation\Service\AnnulationVenteReservationHandler;
use App\Reservation\Service\DeclencherFacturationNoShowHandler;
use App\Reservation\Service\JaugeRessourceMereHandler;
use App\Reservation\Service\PromotionListeAttenteHandler;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Annule une Réservation (POST /reservation/reservations/{id}/annuler, RG-M5-04/09, CA-8). Gratuite
 * et libre **dans** le délai franc (borne inclusive, §7 cas limite) : la place/le quota est libéré(e)
 * et proposé(e) à la liste d'attente (CA-5). **Hors** délai franc : refusée en libre-service
 * (`reservation.annuler_soi`) — seul un agent/responsable (`reservation.annuler`) peut qualifier
 * l'issue en annulation tardive facturée (RG-M5-09).
 *
 * @implements ProcessorInterface<mixed, Reservation>
 */
final class AnnulerReservationProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly JaugeRessourceMereHandler $jaugeMere,
        private readonly PromotionListeAttenteHandler $promotion,
        private readonly DeclencherFacturationNoShowHandler $facturationHandler,
        private readonly AnnulationVenteReservationHandler $annulationVente,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Reservation
    {
        \assert($data instanceof Reservation);

        if (!$data->getStatut()->occupePlace()) {
            throw new ConflictHttpException('Cette réservation n\'est plus annulable (statut actuel : ' . $data->getStatut()->value . ').');
        }

        $maintenant = new \DateTimeImmutable();
        $limite = $data->getDateLimiteAnnulation();
        $dansDelai = $limite === null || $maintenant <= $limite;

        $estAgent = $this->security->isGranted('PERM', 'reservation.annuler');

        if (!$dansDelai && !$estAgent) {
            throw new ConflictHttpException('Annulation hors délai franc refusée en libre-service (RG-M5-04/09) : le quota/la place est perdu, contactez un agent.');
        }

        $creneau = $data->getCreneau();
        $ressource = $creneau?->getRessource();

        if ($dansDelai) {
            $data->setStatut(StatutReservation::AnnuleeLibre);
            $this->em->flush();
        } else {
            $this->facturationHandler->declencher($data, StatutReservation::AnnuleeTardiveFacturee);
        }

        // --- ajout G1/G2 (RG-RESAENC-09/10) : avoir de remboursement (délai franc, Vente validee) ou
        // nettoyage d'une Vente pendante jamais réglée (les deux branches), effet de bord additif.
        $utilisateur = $this->security->getUser();
        \assert($utilisateur instanceof Utilisateur);
        $this->annulationVente->traiter($data, $utilisateur, remboursementAutorise: $dansDelai);
        $this->em->flush();

        if ($ressource !== null) {
            $this->jaugeMere->decrementer($ressource);
            $this->em->flush();
        }

        if ($creneau !== null) {
            $this->promotion->promouvoirSiPlaceDisponible($creneau);
        }

        return $data;
    }
}
