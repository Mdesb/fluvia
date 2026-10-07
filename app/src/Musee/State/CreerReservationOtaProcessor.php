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
use App\Reservation\Service\JaugeRessourceMereHandler;
use App\Vente\Service\LecteurCorps;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\LockMode;
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
        private readonly JaugeRessourceMereHandler $jaugeMere,
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
        if ($creneau === null) {
            throw new ConflictHttpException('RG-MUS-04 : créneau complet (inventaire partagé avec la vente directe).');
        }

        // ── QUOTA ET JAUGE SE CONTRÔLENT SOUS VERROU, DANS LA TRANSACTION QUI ÉCRIT ─────────────────
        //
        // Deux ventes OTA simultanées lisaient la même jauge et le même `quotaConsomme`, puis écrivaient
        // chacune : le créneau et le quota partenaire débordaient ensemble, et le compteur perdait une
        // unité (`quotaConsomme + 1` calculé deux fois sur la même valeur). Même défaut, même remède que
        // `ReserverProcessor` — voir `JaugeCreneauGuard::verrouiller()`.
        //
        // Ordre : l'allocation, puis le créneau. Une réservation ordinaire ne verrouille que le créneau :
        // aucune attente croisée possible. Les relectures viennent après les deux verrous.
        $connexion = $this->em->getConnection();
        $connexion->beginTransaction();
        try {
            $this->em->lock($allocation, LockMode::PESSIMISTIC_WRITE);
            $this->jauge->verrouiller([$creneau], null);
            $this->em->refresh($allocation);

            if ($allocation->estEpuisee()) {
                throw new ConflictHttpException('RG-MUS-04 : quota alloué à ce partenaire épuisé sur ce créneau.');
            }
            if ($this->jauge->estComplet($creneau)) {
                throw new ConflictHttpException('RG-MUS-04 : créneau complet (inventaire partagé avec la vente directe).');
            }

            $reservation = new Reservation();
            $reservation->setCreneau($creneau)
                ->setOrganisateur($beneficiaire)
                ->setEtablissement($allocation->getEtablissement())
                ->setModeDecompte(ModeDecompteReservation::VenteUnite)
                ->setMontantDu($allocation->getPartenaire()?->getTarifNet() ?? '0.00');
            $this->em->persist($reservation);
            // La place prise pèse sur la jauge globale de la ressource (RG-M5-08), comme celle d'une
            // réservation ordinaire : sans cet incrément, l'annulation de cette réservation rendrait
            // au compteur une unité que personne n'y a posée.
            $ressource = $creneau->getRessource();
            if ($ressource !== null) {
                $this->jaugeMere->incrementer($ressource);
            }

            $allocation->setQuotaConsomme($allocation->getQuotaConsomme() + 1);

            $reservationOta = new ReservationOTA();
            $reservationOta->setAllocation($allocation)
                ->setReservationRattachee($reservation)
                ->setHorodatageConfirmation($reservation->getDateCreation());
            $this->em->persist($reservationOta);
            $this->em->flush();
            $connexion->commit();
        } catch (RetryableException $concurrence) {
            if ($connexion->isTransactionActive()) {
                $connexion->rollBack();
            }

            throw new ConflictHttpException('Une autre vente était en cours sur ce créneau : celle-ci n\'a pas été enregistrée. Réessayez.', $concurrence);
        } catch (\Throwable $echec) {
            if ($connexion->isTransactionActive()) {
                $connexion->rollBack();
            }

            throw $echec;
        }

        // Prévenu APRÈS la validation : prévenu avant, le connecteur annoncerait une vente que
        // l'annulation de la transaction aurait effacée.
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
