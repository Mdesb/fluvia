<?php

declare(strict_types=1);

namespace App\Group\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Crm\Entity\Beneficiaire;
use App\Group\Entity\GroupBooking;
use App\Group\Enum\GroupBookingStatus;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\ModeDecompteReservation;
use App\Reservation\Enum\StatutReservation;
use App\Reservation\Service\JaugeCreneauGuard;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Confirme une réservation de groupe (POST /group/bookings/{id}/confirm) : `Option` → `Confirmed`.
 *
 * ── DÉCOMPTE DE LA JAUGE ────────────────────────────────────────────────────────────────────────
 * Si la réservation vise un créneau, confirmer **consomme sa jauge** : on crée UNE `Reservation`
 * socle (`quantity` = effectif + accompagnateurs) sur ce créneau — la jauge est la somme des
 * `Reservation.quantity` (D16/ACT-1), il n'y a pas d'autre voie pour peser dessus. On refuse si les
 * places restantes sont insuffisantes (RG-M5-01, comme le musée), et on annule cette réservation à
 * l'annulation du groupe pour libérer les places.
 *
 * Le responsable de la réservation socle (obligatoire) vient du corps (`responsable`), à défaut du
 * bénéficiaire du client du groupe. Sans responsable résoluble, on refuse plutôt que d'inventer.
 *
 * Une réservation annulée ne se confirme pas ; idempotent sur une déjà confirmée (sans re-créer la jauge).
 *
 * @implements ProcessorInterface<GroupBooking, GroupBooking>
 */
final class ConfirmGroupBookingProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly JaugeCreneauGuard $jauge,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): GroupBooking
    {
        \assert($data instanceof GroupBooking);

        if ($data->getStatus() === GroupBookingStatus::Cancelled) {
            throw new UnprocessableEntityHttpException('Une réservation annulée ne peut pas être confirmée.');
        }

        $creneau = $data->getCreneau();
        if ($creneau !== null && $data->getJaugeReservation() === null) {
            $total = max(1, $data->getEffectif() + $data->getAccompagnateurs());

            if ($this->jauge->placesRestantes($creneau) < $total) {
                throw new ConflictHttpException(sprintf(
                    'Jauge insuffisante : %d place(s) restante(s) sur ce créneau pour un effectif de %d.',
                    $this->jauge->placesRestantes($creneau),
                    $total,
                ));
            }

            $responsable = $this->responsable($data);
            if (!$responsable instanceof Beneficiaire) {
                throw new UnprocessableEntityHttpException(
                    'Responsable requis pour décompter la jauge : fournissez « responsable » (bénéficiaire) '
                    . 'ou rattachez un client au groupe.'
                );
            }

            $reservation = (new Reservation())
                ->setCreneau($creneau)
                ->setOrganisateur($responsable)
                ->setEtablissement($data->getEtablissement())
                ->setModeDecompte(ModeDecompteReservation::Gratuit)
                ->setMontantDu('0.00')
                ->setStatut(StatutReservation::Confirmee)
                ->setQuantity($total);
            $this->em->persist($reservation);
            $data->setJaugeReservation($reservation);
        }

        $data->setStatus(GroupBookingStatus::Confirmed);
        $this->em->flush();

        return $data;
    }

    private function responsable(GroupBooking $booking): ?Beneficiaire
    {
        $corps = $this->lecteur->corps();
        $reference = $corps['responsable'] ?? null;
        if (\is_string($reference) && $reference !== '') {
            $segment = str_contains($reference, '/') ? basename($reference) : $reference;
            if (Uuid::isValid($segment)) {
                $b = $this->em->getRepository(Beneficiaire::class)->find(Uuid::fromString($segment));
                if ($b instanceof Beneficiaire) {
                    return $b;
                }
            }
        }

        $client = $booking->getGroup()?->getClient();
        if ($client !== null) {
            return $this->em->getRepository(Beneficiaire::class)->findOneBy(['client' => $client]);
        }

        return null;
    }
}
