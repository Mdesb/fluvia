<?php

declare(strict_types=1);

namespace App\Reservation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventActor;
use App\Platform\Event\EventBus;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\IssueCreditNoShow;
use App\Reservation\Enum\StatutReservation;
use App\Reservation\Service\AnnulationVenteReservationHandler;
use App\Reservation\Service\DeclencherFacturationNoShowHandler;
use App\Reservation\Service\JaugeRessourceMereHandler;
use App\Reservation\Service\ProjectionAccesReservationHandler;
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
        private readonly ProjectionAccesReservationHandler $projectionAcces,
        private readonly EventBus $eventBus,
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

        $facturationNoShow = null;
        if ($dansDelai) {
            $data->setStatut(StatutReservation::AnnuleeLibre);
            $this->em->flush();
            $this->projectionAcces->revoquerSiProjete($data);
        } else {
            // Branche tardive (RG-ACC3-05) : la révocation est câblée dans
            // DeclencherFacturationNoShowHandler::declencher(), point de passage partagé avec la
            // branche no-show de BasculerNoShowCommand. Retour capturé (RG-CQ5-08) pour construire le
            // payload étendu de booking.cancelled / publier booking.reschedule_requested ci-dessous.
            $facturationNoShow = $this->facturationHandler->declencher($data, StatutReservation::AnnuleeTardiveFacturee);
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

        // SF-1 / D22 — `booking.cancelled`, declare au catalogue depuis l'origine et publie par
        // personne jusqu'ici. Smart Flow en depend pour reproposer un creneau libere, et Revenue
        // Recovery pour la relance.
        //
        // Emis depuis **les deux branches** — annulation libre et annulation tardive facturee — parce
        // que les deux sont des annulations : ce qui les distingue est la facturation, pas la nature
        // de l'acte. Et emis **ici** plutot que depuis `DeclencherFacturationNoShowHandler`, qui est
        // pourtant le point de passage commode : ce handler recoit le statut cible **en argument**, il
        // ne sait donc pas lequel de `booking.cancelled` ou `booking.no_show` il est en train de
        // produire. Emettre depuis lui confondrait les deux (remarque de claude-C, 24/08).
        //
        // `leadTimeMinutes` est la charge utile qui compte : c'est le delai entre l'annulation et le
        // debut du creneau, donc ce qui permet a Smart Flow de decider si la place est revendable.
        $etablissement = $data->getEtablissement();
        if ($creneau !== null && $etablissement !== null) {
            $this->eventBus->publish(new DomainEvent(
                'booking.cancelled',
                new EventTenant($etablissement->getId()),
                new EventSubject('Reservation', (string) $data->getId()),
                [
                    'slotId' => (string) $creneau->getId(),
                    'leadTimeMinutes' => max(0, (int) round(
                        ($creneau->getDebut()->getTimestamp() - $maintenant->getTimestamp()) / 60
                    )),
                    'withinFreeWindow' => $dansDelai,
                    // RG-CQ5-08 — extension additive (plan-cq5.md §3.8), branche tardive uniquement :
                    // la branche libre n'a jamais résolu de RegleAnnulation.
                    ...(!$dansDelai && $facturationNoShow !== null ? [
                        'creditIssue' => $facturationNoShow->getIssueCreditNoShow()?->value,
                        'creditRestoredAmount' => ($facturationNoShow->isCreditActionne() && $facturationNoShow->isCreditRestitue()) ? 1 : 0,
                    ] : []),
                ],
                new EventActor($utilisateur->getId()),
            ));

            // RG-CQ5-08 — même condition et même acteur que booking.cancelled ci-dessus, cohérence
            // avec BasculerNoShowCommand.
            if (!$dansDelai
                && $facturationNoShow?->getIssueCreditNoShow() === IssueCreditNoShow::RestoredWithReschedule
                && $facturationNoShow->isCreditActionne() && $facturationNoShow->isCreditRestitue()) {
                $this->eventBus->publish(new DomainEvent(
                    'booking.reschedule_requested',
                    new EventTenant($etablissement->getId()),
                    new EventSubject('Reservation', (string) $data->getId()),
                    [
                        'customerId' => (string) $data->getOrganisateur()?->getId(),
                        'reservationRef' => (string) $data->getId(),
                        'slotId' => (string) $creneau->getId(),
                        'droitId' => (string) $facturationNoShow->getDroitAccesRestitueRef(),
                    ],
                    new EventActor($utilisateur->getId()),
                ));
            }
        }

        return $data;
    }
}
