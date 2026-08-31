<?php

declare(strict_types=1);

namespace App\Reservation\Command;

use App\Reservation\Entity\Creneau;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventBus;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\IssueCreditNoShow;
use App\Reservation\Enum\StatutCreneau;
use App\Reservation\Enum\StatutPaiementParticipant;
use App\Reservation\Enum\StatutReservation;
use App\Reservation\Service\DeclencherFacturationNoShowHandler;
use App\Reservation\Service\JaugeRessourceMereHandler;
use App\Reservation\Service\ResolveurRegleAnnulation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `reservation:no-show:basculer` (§4.8, CA-9) : à l'issue d'un créneau (fin + marge paramétrable de
 * la `RegleAnnulation` applicable, défaut 0 si aucune règle active), bascule automatiquement en
 * `no_show_facture` toute réservation confirmée sans présence confirmée (ni émargement, ni passage
 * d'accès), déclenchant `RG-M5-09`. Les réservations avec présence confirmée basculent en `honoree`.
 * Les parts de paiement partagé encore `en_attente` (hors organisateur) sont imputées à
 * l'organisateur (RG-M5-10, CA-13, §4.10).
 */
#[AsCommand(name: 'reservation:no-show:basculer', description: 'Bascule automatiquement les réservations non honorées en no-show à l\'issue du créneau (CA-9).')]
final class BasculerNoShowCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ResolveurRegleAnnulation $resolveurRegle,
        private readonly DeclencherFacturationNoShowHandler $facturationHandler,
        private readonly EventBus $eventBus,
        private readonly JaugeRessourceMereHandler $jaugeMere,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $traites = $this->basculer(new \DateTimeImmutable());
        $io->success(sprintf('%d réservation(s) traitée(s) à l\'issue de leur créneau.', $traites));

        return Command::SUCCESS;
    }

    public function basculer(\DateTimeImmutable $maintenant): int
    {
        /** @var list<Creneau> $creneaux */
        $creneaux = $this->em->getRepository(Creneau::class)->createQueryBuilder('c')
            ->andWhere('c.statut = :planifie')
            ->andWhere('c.fin <= :maintenant')
            ->setParameter('planifie', StatutCreneau::Planifie->value)
            ->setParameter('maintenant', $maintenant, 'datetime_immutable')
            ->getQuery()
            ->getResult();

        $traites = 0;
        foreach ($creneaux as $creneau) {
            $regle = $this->resolveurRegle->resoudre($creneau);
            $marge = $regle?->getMargePostCreneauMinutes() ?? 0;
            $seuil = $creneau->getFin()->modify(sprintf('+%d minutes', $marge));
            if ($seuil > $maintenant) {
                continue; // marge non encore écoulée.
            }

            /** @var list<Reservation> $reservations */
            $reservations = $this->em->getRepository(Reservation::class)->findBy(['creneau' => $creneau, 'statut' => StatutReservation::Confirmee->value]);
            foreach ($reservations as $reservation) {
                if ($reservation->isPresenceConfirmee()) {
                    $reservation->setStatut(StatutReservation::Honoree);
                } else {
                    $facturation = $this->facturationHandler->declencher($reservation, StatutReservation::NoShowFacture);

                    // SF-1 / D22 — `booking.no_show`. Emis **ici seulement**, dans la branche qui
                    // constate l'absence : la branche voisine marque une presence confirmee et n'a
                    // rien a annoncer. Le handler partage ne saurait pas les distinguer.
                    //
                    // `amountAtRisk` est le montant du au moment du constat : c'est ce que Revenue
                    // Recovery relance, et ce que D27 restitue ou decompte selon la regle.
                    $etablissementNoShow = $reservation->getEtablissement();
                    if ($etablissementNoShow !== null) {
                        $this->eventBus->publish(new DomainEvent(
                            'booking.no_show',
                            new EventTenant($etablissementNoShow->getId()),
                            new EventSubject('Reservation', (string) $reservation->getId()),
                            [
                                'customerId' => (string) $reservation->getOrganisateur()?->getId(),
                                // Revue de claude-C (24/08) : j'avais pris `Reservation::getMontantDu()`,
                                // qui vaut **`0.00` sur toute seance deja payee** — quota de formule,
                                // gratuite, commande boutique, dossier groupe. Six chemins d'ecriture
                                // le mettent a zero, et ce sont exactement les cas de D27. L'evenement
                                // annoncait donc « rien en jeu » precisement quand quelque chose
                                // l'etait.
                                //
                                // Le montant en jeu n'est pas ce que le client doit encore, c'est ce
                                // que la regle d'annulation facture pour l'absence. `declencher()`
                                // rend la `FacturationNoShow` : on prend la sienne.
                                'amountAtRisk' => $facturation?->getMontant() ?? '0.00',
                                // Distingue « 0 parce qu'aucune regle ne s'applique » de « 0 parce que
                                // la regle ne facture rien ». Sans cela, un abonne ne peut pas savoir
                                // s'il doit relancer ou se taire.
                                'hasBillingRule' => $facturation !== null,
                                'slotId' => (string) $creneau->getId(),
                                // RG-CQ5-08 — extension additive (plan-cq5.md §3.7). `creditIssue`
                                // absent (pas juste `null` dans le JSON final : la clé n'est écrite que
                                // si une règle a été résolue) si $facturation === null.
                                ...($facturation !== null ? [
                                    'creditIssue' => $facturation->getIssueCreditNoShow()?->value,
                                    'creditRestoredAmount' => ($facturation->isCreditActionne() && $facturation->isCreditRestitue()) ? 1 : 0,
                                ] : []),
                            ],
                        ));

                        // RG-CQ5-08 — booking.reschedule_requested : publié UNIQUEMENT si issue =
                        // RestoredWithReschedule ET creditActionne ET creditRestitue (renforcement
                        // volontaire par rapport à la lettre stricte de RG-CQ5-08, cf. plan §8 risque
                        // n°2 : jamais annoncer un report pour un crédit qui, en pratique, n'a pas
                        // bougé). Émis depuis CE call site, jamais depuis
                        // DeclencherFacturationNoShowHandler (même raison que booking.cancelled/
                        // booking.no_show déjà actée dans AnnulerReservationProcessor).
                        if ($facturation?->getIssueCreditNoShow() === IssueCreditNoShow::RestoredWithReschedule
                            && $facturation->isCreditActionne() && $facturation->isCreditRestitue()) {
                            $this->eventBus->publish(new DomainEvent(
                                'booking.reschedule_requested',
                                new EventTenant($etablissementNoShow->getId()),
                                new EventSubject('Reservation', (string) $reservation->getId()),
                                [
                                    'customerId' => (string) $reservation->getOrganisateur()?->getId(),
                                    'reservationRef' => (string) $reservation->getId(),
                                    'slotId' => (string) $creneau->getId(),
                                    'droitId' => (string) $facturation->getDroitAccesRestitueRef(),
                                ],
                            ));
                        }
                    }
                }

                if ($creneau->getRessource() !== null) {
                    $this->jaugeMere->decrementer($creneau->getRessource(), $reservation->getQuantity());
                }

                foreach ($reservation->getParticipants() as $participant) {
                    if (!$participant->isEstOrganisateur() && $participant->getStatutPaiement() === StatutPaiementParticipant::EnAttente) {
                        $participant->setStatutPaiement(StatutPaiementParticipant::ImputeOrganisateur);
                    }
                }

                ++$traites;
            }

            $creneau->setStatut(StatutCreneau::Termine);
        }

        $this->em->flush();

        return $traites;
    }
}
