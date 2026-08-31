<?php

declare(strict_types=1);

namespace App\Reservation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\StatutCreneau;
use App\Reservation\Enum\StatutReservation;
use App\Reservation\Service\JaugeRessourceMereHandler;
use App\Reservation\Service\StockCardCreditHandler;
use App\Reservation\Service\ProjectionAccesReservationHandler;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Annulation d'un Créneau par le gestionnaire (POST /reservation/creneaux/{id}/annuler) : aucun
 * no-show ni frais côté bénéficiaire, le quota/la place est restitué(e) (§7 cas limite). Toutes les
 * réservations confirmées basculent en `annulee_libre`.
 *
 * @implements ProcessorInterface<mixed, Creneau>
 */
final class AnnulerCreneauProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JaugeRessourceMereHandler $jaugeMere,
        private readonly StockCardCreditHandler $carteStock,
        private readonly ProjectionAccesReservationHandler $projectionAcces,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Creneau
    {
        \assert($data instanceof Creneau);

        /** @var list<Reservation> $reservations */
        $reservations = $this->em->getRepository(Reservation::class)->findBy(['creneau' => $data]);
        foreach ($reservations as $reservation) {
            if ($reservation->getStatut()->occupePlace()) {
                $reservation->setStatut(StatutReservation::AnnuleeLibre);
                $this->projectionAcces->revoquerSiProjete($reservation);
                if ($data->getRessource() !== null) {
                    $this->jaugeMere->decrementer($data->getRessource(), $reservation->getQuantity());
                }
                // CQ-3 + CQ-6 — c'est l'exploitant qui annule le créneau : le client n'y est pour
                // rien, sa séance lui revient toujours. Aucune issue commerciale à arbitrer ici,
                // contrairement au no-show (D27).
                $this->carteStock->restituer($reservation->getCreditDroitRef());
            }
        }

        $data->setStatut(StatutCreneau::Annule);
        $this->em->flush();

        return $data;
    }
}
