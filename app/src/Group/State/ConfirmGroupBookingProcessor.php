<?php

declare(strict_types=1);

namespace App\Group\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Crm\Entity\Beneficiaire;
use App\Group\Entity\GroupBooking;
use App\Group\Entity\GroupGratuite;
use App\Group\Enum\GroupBookingGrain;
use App\Group\Enum\GroupBookingStatus;
use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\ModeDecompteReservation;
use App\Reservation\Enum\StatutReservation;
use App\Reservation\Service\JaugeCreneauGuard;
use App\Vente\Service\LecteurCorps;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Confirme une réservation de groupe (POST /group/bookings/{id}/confirm) : `Option` → `Confirmed`.
 *
 * ── DÉCOMPTE DE LA JAUGE, AU GRAIN ET AVEC LA SÉMANTIQUE ARGENT DU MUSÉE ─────────────────────────
 * Si la réservation vise un créneau, confirmer **consomme sa jauge** en créant des `Reservation`
 * socle sur ce créneau (la jauge est la somme des `Reservation.quantity`, D16/ACT-1). On refuse si
 * les places restantes sont insuffisantes (RG-M5-01 / RG-MUS-01, comme le musée), et on annule ces
 * réservations à l'annulation du groupe pour libérer les places.
 *
 * Absorption musée (arbitrage Maxime « fidèle : étendre App\Group », 08/09) : chaque entrée porte le
 * bon **mode de décompte**. Les entrées PAYANTES sont en `vente_unite` avec `montantDu = 0.00`
 * (paiement **différé** : la somme est portée par le devis / la facture, aucune `Vente` n'est créée
 * ici — RG-MUS-03), les entrées GRATUITES (contingent, `GroupGratuite` déjà octroyées) en `gratuit`.
 * Le nombre de gratuités est plafonné à l'effectif ; les deux modes pèsent la même jauge (somme = N).
 *
 * Grain : `per_person` crée une réservation par visiteur (quantité 1) ; `per_group` un bloc par mode
 * (quantité = nombre d'entrées de ce mode). Les gratuités octroyées APRÈS la confirmation ne changent
 * pas rétroactivement le mode des réservations déjà posées ; elles réduisent le devis (ordre naturel :
 * octroyer puis confirmer, comme le musée).
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
        if ($creneau === null || !$data->getJaugeReservations()->isEmpty()) {
            $data->setStatus(GroupBookingStatus::Confirmed);
            $this->em->flush();

            return $data;
        }

        // ── LA JAUGE SE POSE SOUS VERROU, DANS LA TRANSACTION QUI ÉCRIT ───────────────────────────────
        //
        // Deux courses fermées ici :
        //  - deux confirmations de groupes différents sur le même créneau lisaient la même jauge et la
        //    débordaient ensemble — même défaut, même remède que `ReserverProcessor` ;
        //  - deux confirmations du MÊME groupe (double clic, deux onglets) voyaient toutes deux « aucune
        //    réservation posée » et posaient la jauge deux fois. Le verrou sur la réservation de groupe,
        //    suivi d'une relecture, rend la seconde idempotente.
        //
        // Le responsable se résout AVANT la transaction : ce sont des lectures, et une lecture faite avant
        // les verrous figerait l'instantané (voir `JaugeCreneauGuard::verrouiller()`). Son refus reste
        // posé APRÈS le contrôle de jauge, dans l'ordre d'origine.
        $responsable = $this->responsable($data);

        $connexion = $this->em->getConnection();
        $connexion->beginTransaction();
        try {
            $this->em->lock($data, LockMode::PESSIMISTIC_WRITE);
            $this->jauge->verrouiller([$creneau], null);
            $this->em->refresh($data);

            if ($data->getStatus() === GroupBookingStatus::Cancelled) {
                throw new UnprocessableEntityHttpException('Une réservation annulée ne peut pas être confirmée.');
            }
            // Une confirmation concurrente a déjà posé la jauge : on ne la pose pas une seconde fois.
            if ($data->getJaugeReservations()->isEmpty()) {
                $this->poserJauge($data, $creneau, $responsable);
            }

            $data->setStatus(GroupBookingStatus::Confirmed);
            $this->em->flush();
            $connexion->commit();
        } catch (RetryableException $concurrence) {
            if ($connexion->isTransactionActive()) {
                $connexion->rollBack();
            }

            throw new ConflictHttpException('Une autre réservation était en cours sur ce créneau : la confirmation n\'a pas été enregistrée. Réessayez.', $concurrence);
        } catch (\Throwable $echec) {
            if ($connexion->isTransactionActive()) {
                $connexion->rollBack();
            }

            throw $echec;
        }

        return $data;
    }

    /** Contrôle et pose la jauge du groupe — appelé SOUS le verrou posé par `process()`, qui valide. */
    private function poserJauge(GroupBooking $data, Creneau $creneau, ?Beneficiaire $responsable): void
    {
        $total = max(1, $data->getEffectif() + $data->getAccompagnateurs());

        if ($this->jauge->placesRestantes($creneau) < $total) {
            throw new ConflictHttpException(sprintf(
                'Jauge insuffisante : %d place(s) restante(s) sur ce créneau pour un effectif de %d.',
                $this->jauge->placesRestantes($creneau),
                $total,
            ));
        }

        if (!$responsable instanceof Beneficiaire) {
            throw new UnprocessableEntityHttpException(
                'Responsable requis pour décompter la jauge : fournissez « responsable » (bénéficiaire) '
                . 'ou rattachez un client au groupe.'
            );
        }

        // Répartition payant / gratuit (musée : vente_unite différée vs gratuit).
        $gratuites = min($this->nombreGratuites($data), $total);
        $payantes = $total - $gratuites;

        $perPerson = $data->getGrain() === GroupBookingGrain::PerPerson;
        if ($perPerson) {
            // Un billet par visiteur : N réservations de quantité 1.
            $this->poser($data, $creneau, $responsable, ModeDecompteReservation::VenteUnite, $payantes, 1);
            $this->poser($data, $creneau, $responsable, ModeDecompteReservation::Gratuit, $gratuites, 1);
        } else {
            // Bloc : au plus une réservation par mode, portant la quantité de ce mode.
            if ($payantes > 0) {
                $this->poser($data, $creneau, $responsable, ModeDecompteReservation::VenteUnite, 1, $payantes);
            }
            if ($gratuites > 0) {
                $this->poser($data, $creneau, $responsable, ModeDecompteReservation::Gratuit, 1, $gratuites);
            }
        }
    }

    /**
     * Crée `$nombre` réservations socle de `$quantiteParLigne` places, dans le mode donné, et les
     * rattache à la réservation de groupe (pour libération à l'annulation).
     */
    private function poser(
        GroupBooking $booking,
        Creneau $creneau,
        Beneficiaire $responsable,
        ModeDecompteReservation $mode,
        int $nombre,
        int $quantiteParLigne,
    ): void {
        for ($i = 0; $i < $nombre; ++$i) {
            $reservation = (new Reservation())
                ->setCreneau($creneau)
                ->setOrganisateur($responsable)
                ->setEtablissement($booking->getEtablissement())
                ->setModeDecompte($mode)
                ->setMontantDu('0.00')
                ->setStatut(StatutReservation::Confirmee)
                ->setQuantity($quantiteParLigne);
            $this->em->persist($reservation);
            $booking->addJaugeReservation($reservation);
        }
    }

    /** Somme des gratuités déjà octroyées à cette réservation de groupe (contingents transverses). */
    private function nombreGratuites(GroupBooking $booking): int
    {
        $total = 0;
        foreach ($this->em->getRepository(GroupGratuite::class)->findBy(['booking' => $booking]) as $gratuite) {
            $total += $gratuite->getQuantite();
        }

        return $total;
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
