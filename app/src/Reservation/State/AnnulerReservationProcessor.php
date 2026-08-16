<?php

declare(strict_types=1);

namespace App\Reservation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\StatutReservation;
use App\Reservation\Service\DeclencherFacturationNoShowHandler;
use App\Reservation\Service\JaugeRessourceMereHandler;
use App\Reservation\Service\PromotionListeAttenteHandler;
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
