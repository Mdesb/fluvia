<?php

declare(strict_types=1);

namespace App\Musee\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Crm\Entity\Beneficiaire;
use App\Musee\Entity\AllocationQuotaOTA;
use App\Musee\Entity\ReservationOTA;
use App\Musee\Port\ConnecteurOtaInterface;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\ModeDecompteReservation;
use App\Reservation\Service\JaugeCreneauGuard;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * POST /musee/reservations-ota (US-MUSEE-07/08, RG-MUS-04, CA-7) : simule la réception d'une vente
 * confirmée par le connecteur OTA (port stub, §1.8 du plan) — crée **1 `Reservation`** (module socle
 * Réservation réutilisé) contre le créneau alloué, décrémente le **même inventaire réel** que la
 * vente directe et `AllocationQuotaOTA.quotaConsomme` (anti sur-vente, refuse si l'allocation ou le
 * créneau est épuisé). Corps : { "allocation": iri|uuid, "beneficiaire": iri|uuid }.
 *
 * @implements ProcessorInterface<mixed, ReservationOTA>
 */
final class CreerReservationOtaProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly JaugeCreneauGuard $jauge,
        private readonly ConnecteurOtaInterface $connecteur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ReservationOTA
    {
        $corps = $this->lecteur->corps();

        $allocation = $this->resoudre(AllocationQuotaOTA::class, $corps['allocation'] ?? null, 'allocation');
        \assert($allocation instanceof AllocationQuotaOTA);
        $beneficiaire = $this->resoudre(Beneficiaire::class, $corps['beneficiaire'] ?? null, 'beneficiaire');
        \assert($beneficiaire instanceof Beneficiaire);

        if ($allocation->estEpuisee()) {
            throw new ConflictHttpException('RG-MUS-04 : quota alloué à ce partenaire épuisé sur ce créneau.');
        }

        $creneau = $allocation->getCreneau();
        if ($creneau === null || $this->jauge->estComplet($creneau)) {
            throw new ConflictHttpException('RG-MUS-04 : créneau complet (inventaire partagé avec la vente directe).');
        }

        $reservation = new Reservation();
        $reservation->setCreneau($creneau)
            ->setOrganisateur($beneficiaire)
            ->setEtablissement($allocation->getEtablissement())
            ->setModeDecompte(ModeDecompteReservation::VenteUnite)
            ->setMontantDu($allocation->getPartenaire()?->getTarifNet() ?? '0.00');
        $this->em->persist($reservation);

        $allocation->setQuotaConsomme($allocation->getQuotaConsomme() + 1);

        $reservationOta = new ReservationOTA();
        $reservationOta->setAllocation($allocation)
            ->setReservationRattachee($reservation)
            ->setHorodatageConfirmation($reservation->getDateCreation());
        $this->em->persist($reservationOta);
        $this->em->flush();

        $this->connecteur->notifierAllocation($allocation);

        return $reservationOta;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $classe
     *
     * @return T
     */
    private function resoudre(string $classe, mixed $reference, string $champ): object
    {
        $uuid = $this->uuid($reference);
        if ($uuid === null) {
            throw new UnprocessableEntityHttpException(sprintf('Référence « %s » obligatoire (UUID ou IRI).', $champ));
        }
        $entite = $this->em->getRepository($classe)->find($uuid);
        if ($entite === null) {
            throw new UnprocessableEntityHttpException(sprintf('%s introuvable.', $champ));
        }

        return $entite;
    }

    private function uuid(mixed $reference): ?Uuid
    {
        if (!\is_string($reference) || $reference === '') {
            return null;
        }
        $segment = str_contains($reference, '/') ? basename($reference) : $reference;

        return Uuid::isValid($segment) ? Uuid::fromString($segment) : null;
    }
}
