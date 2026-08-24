<?php

declare(strict_types=1);

namespace App\Reservation\Service;

use App\Acces\Entity\Appairage;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Support;
use App\Acces\Service\VersionSnapshotSequencer;
use App\Reservation\Entity\ProjectionAccesReservation;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\IssueCreditNoShow;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Applique l'`IssueCreditNoShow` résolue par `RegleAnnulation` à la Réservation qui bascule en
 * no-show / annulation tardive facturée (RG-CQ5-04/05, plan-cq5.md §3.5). Lit/écrit directement
 * `App\Acces\Entity\DroitAcces` — même précédent de couplage que `ProjectionAccesReservationHandler`
 * (plan §3.4) : aucun fichier `App\Acces\*` n'est modifié pour ce comportement.
 *
 * Nom entièrement anglais (D5, fichier neuf) : le plan proposait `AppliquerIssueCreditNoShowHandler`
 * (« Appliquer » = français) — renommé ici pour un fichier réellement nouveau (cf. rapport
 * d'implémentation, écart documenté au plan).
 *
 * N'est appelé qu'APRÈS que `DeclencherFacturationNoShowHandler` ait persisté+flushé la
 * `FacturationNoShow` (RG-CQ5-07, point d'idempotence, plan §3.6) : un second appel pour la même
 * réservation n'atteint jamais ce service, la contrainte unique `reservation_id` ayant déjà fait
 * échouer le flush précédent.
 */
final class ApplyNoShowCreditIssueHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly VersionSnapshotSequencer $sequencer,
    ) {
    }

    public function apply(Reservation $reservation, IssueCreditNoShow $issue): NoShowCreditIssueResult
    {
        // RG-CQ5-04 — résolution du droit créditable, même repository lookup que
        // ProjectionAccesReservationHandler::revoquerSiProjete().
        $projection = $this->em->getRepository(ProjectionAccesReservation::class)
            ->findOneBy(['reservation' => $reservation]);
        if (!$projection instanceof ProjectionAccesReservation || $projection->getDroitAccesRef() === null) {
            return NoShowCreditIssueResult::noCredit(); // aucune projection — cas universel (§3.2 spec).
        }

        $droit = $this->em->getRepository(DroitAcces::class)->find($projection->getDroitAccesRef());
        if (!$droit instanceof DroitAcces || $droit->getCreditRestant() === null) {
            return NoShowCreditIssueResult::noCredit(); // projeté mais sans crédit — cas universel Booking.
        }

        // RG-CQ5-10 — garde défensive de cloisonnement (C19) : ne devrait JAMAIS déclencher, puisque
        // ProjectionAccesReservationHandler pose systématiquement droit.etablissement =
        // reservation.etablissement à la création (invariant ACC-3). Échec fermé documenté (no-op,
        // pas d'exception) plutôt qu'un throw qui interromprait tout le batch BasculerNoShowCommand
        // pour une seule réservation suspecte.
        if ((string) $droit->getEtablissement()?->getId() !== (string) $reservation->getEtablissement()?->getId()) {
            return NoShowCreditIssueResult::noCredit();
        }

        if ($issue === IssueCreditNoShow::Decremented) {
            // RG-CQ5-05 — sous l'hypothèse §3.3 (décompte au booking), le crédit déjà pris reste pris :
            // AUCUNE écriture. Volontairement symétrique et indépendant du point de décompte réel.
            return NoShowCreditIssueResult::decremented($droit->getId());
        }

        // Restored / RestoredWithReschedule — même patron atomique que CardRechargeHandler:139-159 /
        // ValidationPassageHandler:188-204 : UPDATE SQL conditionnel, jamais de read-modify-write.
        $droitId = $droit->getId();
        $restitue = false;
        $coursePerdue = false;

        $this->connection->transactional(function () use ($droit, $droitId, &$restitue, &$coursePerdue): void {
            $affectees = (int) $this->connection->executeStatement(
                'UPDATE acces_droit_acces SET credit_restant = credit_restant + 1 WHERE id = UNHEX(:hex) AND credit_restant IS NOT NULL',
                ['hex' => bin2hex($droitId->toBinary())],
            );
            if ($affectees === 0) {
                // Garde de concurrence (§8 cas limite spec) : le crédit a disparu entre la lecture
                // RG-CQ5-04 ci-dessus et cet UPDATE (théorique, aucun chemin connu aujourd'hui). On ne
                // met PAS $restitue à true — un droit a bien été trouvé mais la restitution a échoué :
                // raceLost() garde creditActioned=true / creditRestored=false, distinct de noCredit()
                // (« aucun droit créditable n'existait »), traçable et non silencieux (plan §8 risque n°5).
                $coursePerdue = true;

                return;
            }

            // Mirage en mémoire par RECHARGEMENT (refresh), jamais par calcul relatif — même garde que
            // CardRechargeHandler:159 contre l'écrasement d'une écriture concurrente par un flush()
            // Doctrine basé sur une valeur périmée.
            $this->em->refresh($droit);
            $restitue = true;

            // RG-CQ5-05 dernier alinéa — si un Appairage actif existe pour ce droit, un terminal
            // hors-ligne doit voir le nouveau solde (même règle que D23).
            $appairage = $this->em->getRepository(Appairage::class)
                ->findOneBy(['droit' => $droit, 'actif' => true]);
            $support = $appairage?->getSupport();
            if ($support instanceof Support) {
                $version = $this->sequencer->suivant();
                $this->connection->executeStatement(
                    'UPDATE acces_support SET version_maj = :v WHERE id = UNHEX(:hex)',
                    ['v' => $version, 'hex' => bin2hex($support->getId()->toBinary())],
                );
                $support->setVersionMaj($version);
            }

            $this->em->flush();
        });

        if ($restitue) {
            return NoShowCreditIssueResult::restored($droitId);
        }

        return $coursePerdue
            ? NoShowCreditIssueResult::raceLost($droitId)
            : NoShowCreditIssueResult::noCredit();
    }
}
