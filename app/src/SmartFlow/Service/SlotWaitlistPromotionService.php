<?php

declare(strict_types=1);

namespace App\SmartFlow\Service;

use App\Crm\Entity\Beneficiaire;
use App\Organisation\Entity\Etablissement;
use App\Platform\Notification\ClientNotification;
use App\Platform\Notification\ClientNotifierInterface;
use App\Platform\Notification\NotificationBasis;
use App\Platform\Notification\NotificationChannel;
use App\SmartFlow\Entity\RescheduleProposal;
use App\SmartFlow\Entity\SlotWaitlistEntry;
use App\SmartFlow\Enum\RescheduleProposalStatus;
use App\SmartFlow\Enum\SlotWaitlistEntryStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Promotion FIFO de la liste d'attente Smart Flow (RG-SF-06/07, plan-smart-flow.md T9) : matérialise
 * une `RescheduleProposal` (`sourceWaitlistEntryRef` renseigné, `originReservationRef`/`entitlementRef`
 * nuls — §1 note de conception, une promotion Smart Flow ne retrace aucun crédit) pour le candidat le
 * plus ancien (`rank` croissant, `status = waiting`) de la ressource libérée, avec un délai de
 * confirmation court (⚠ HYPOTHÈSE non chiffrée par une source produit, plan §0.7 : 15 minutes par
 * défaut, même ordre de grandeur que `PromotionListeAttenteHandler::DELAI_CONFIRMATION_MINUTES` côté
 * `App\Reservation`).
 *
 * Réutilisée par `App\SmartFlow\EventListener\SlotReleasedListener` (auto-consommé, RG-SF-06) **et**
 * par `App\SmartFlow\Command\ExpireSlotWaitlistPromotionsCommand` (tente l'inscription suivante quand
 * la précédente expire sans confirmation, RG-SF-07).
 */
final class SlotWaitlistPromotionService
{
    /** ⚠ HYPOTHÈSE non chiffrée par une source produit (plan §0.7) — à rendre paramétrable par établissement. */
    private const PROMOTION_EXPIRATION_MINUTES = 15;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClientNotifierInterface $notifier,
        private readonly ReservationSlotReader $slotReader,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Tente une promotion sur la ressource `$resourceId` pour le créneau libéré `$slotId` (RG-SF-06) —
     * `null` si aucune inscription `waiting` n'existe sur cette ressource (pas un échec, un simple
     * constat : la revente non ciblée, hors périmètre de ce lot, peut alors s'appliquer).
     *
     * `$occurredAt` (D37) est l'instant métier de la promotion : celui de `slot.released` quand
     * l'appelant est `App\SmartFlow\EventListener\SlotReleasedListener`, celui de l'exécution de la
     * tâche planifiée quand l'appelant est `App\SmartFlow\Command\ExpireSlotWaitlistPromotionsCommand`
     * (l'expiration constatée déclenche elle-même la promotion suivante, il n'existe pas d'instant
     * antérieur plus légitime).
     */
    /**
     * Clot une proposition et TENTE DE SERVIR LE SUIVANT (RG-SF-07).
     *
     * Extrait de `ExpireSlotWaitlistPromotionsCommand`, qui portait seule cette sequence : la route
     * `POST .../decline` se contentait de basculer le statut, donc un client qui repondait
     * « non merci » bloquait la place pour tous les suivants.
     *
     * ⚠ LE `flush` INTERCALE N'EST PAS DECORATIF. `promoteNext()` relit les inscriptions `waiting`
     * par une requete DQL fraiche : sans lui, l'inscription qu'on vient de consommer serait encore
     * `waiting` en base et pourrait etre REPROMUE elle-meme. C'est precisement ce qu'on oublierait
     * en recopiant cette sequence ailleurs.
     *
     * `$statutInscription` distingue les deux issues, qui ne sont pas le meme fait : `Expired` quand
     * personne n'a repondu, `Cancelled` quand la personne a refuse.
     *
     * Une proposition I1 (report de no-show) n'a pas de `sourceWaitlistEntryRef` : il n'y a alors
     * aucune inscription a consommer ni personne a servir ensuite.
     */
    public function closeAndPromoteNext(
        RescheduleProposal $proposal,
        SlotWaitlistEntryStatus $statutInscription,
        \DateTimeImmutable $occurredAt,
    ): ?RescheduleProposal {
        $proposal->setStatus(RescheduleProposalStatus::Expired);

        $entryRef = $proposal->getSourceWaitlistEntryRef();
        $entry = $entryRef !== null
            ? $this->em->getRepository(SlotWaitlistEntry::class)->find($entryRef)
            : null;

        if (!$entry instanceof SlotWaitlistEntry) {
            $this->em->flush();

            return null;
        }

        $entry->setStatus($statutInscription);
        $establishment = $entry->getEstablishment();
        $this->em->flush();

        if ($establishment === null) {
            return null;
        }

        return $this->promoteNext($establishment, $entry->getResourceId(), $proposal->getOriginSlotId(), $occurredAt);
    }

    public function promoteNext(Etablissement $establishment, Uuid $resourceId, Uuid $slotId, \DateTimeImmutable $occurredAt): ?RescheduleProposal
    {
        // ⚠ LA FENETRE DE RECHERCHE EST HONOREE DEPUIS LE 05/09 — elle ne l'etait pas avant.
        //
        // `searchWindowStart`/`End` etaient exiges a l'inscription, stockes, et jamais lus : on
        // pouvait proposer un creneau de decembre a quelqu'un qui avait demande la semaine
        // prochaine. Le filtre a besoin de la DATE du creneau, que cette methode ne recoit pas —
        // elle ne recoit qu'un identifiant — d'ou cette relecture.
        $creneau = $this->slotReader->snapshotCreneau($slotId, $establishment->getId());

        if ($creneau === null) {
            // ⚠ REPLI BRUYANT, JAMAIS SILENCIEUX. Sans la date, la fenetre est inapplicable.
            // Refuser toute promotion priverait quelqu'un d'une vraie place sur un echec de
            // lecture ; on retombe donc sur le comportement d'avant, en le DISANT. Un repli muet
            // serait indiscernable d'un filtre qui fonctionne.
            $this->logger?->warning('smart_flow.promotion.slot_illisible', [
                'slot' => (string) $slotId,
                'consequence' => 'fenetre de recherche non appliquee pour cette promotion',
            ]);
        }

        $requete = $this->em->getRepository(SlotWaitlistEntry::class)->createQueryBuilder('e')
            ->andWhere('e.establishment = :establishment')
            ->andWhere('e.resourceId = :resourceId')
            ->andWhere('e.status = :status')
            ->setParameter('establishment', $establishment->getId(), 'uuid')
            ->setParameter('resourceId', $resourceId, 'uuid')
            ->setParameter('status', SlotWaitlistEntryStatus::Waiting->value)
            ->orderBy('e.rank', 'ASC')
            ->setMaxResults(1);

        if ($creneau !== null) {
            // La comparaison porte sur le DEBUT du creneau : « je cherche entre le 10 et le 15 »
            // veut dire qu'un creneau COMMENCANT dans cette plage convient, pas qu'il doive s'y
            // terminer.
            $requete
                ->andWhere('e.searchWindowStart <= :debutCreneau')
                ->andWhere('e.searchWindowEnd >= :debutCreneau')
                ->setParameter('debutCreneau', $creneau->start, 'datetime_immutable');
        }

        /** @var SlotWaitlistEntry|null $entry */
        $entry = $requete->getQuery()->getOneOrNullResult();

        if (!$entry instanceof SlotWaitlistEntry) {
            return null;
        }

        $now = new \DateTimeImmutable();

        $proposal = new RescheduleProposal();
        $proposal->setEstablishment($establishment)
            ->setSourceWaitlistEntryRef($entry->getId())
            ->setOriginSlotId($slotId)
            ->setCustomerId($entry->getBeneficiaryId())
            ->setStatus(RescheduleProposalStatus::Proposed)
            ->setProposedSlotId($slotId)
            ->setExpiresAt($now->modify(sprintf('+%d minutes', self::PROMOTION_EXPIRATION_MINUTES)))
            ->setLastSearchAttemptAt($now);

        $entry->setStatus(SlotWaitlistEntryStatus::Promoted)
            ->setPromotedProposalRef($proposal->getId());

        $this->em->persist($proposal);
        $this->em->flush();

        // ⚠ UN BENEFICIAIRE N'EST PAS UN CLIENT, ET LE NOTIFIEUR ATTEND UN CLIENT.
        //
        // Jusqu'au 06/09, on passait `beneficiaryId` tel quel à `ClientNotification::$clientId`.
        // `ConsentGatedNotifier` cherche alors `Client::find()`, ne trouve rien, et rend `Refusee` —
        // exactement ce qu'il rend quand un client a REFUSÉ les messages. La place était donc
        // proposée à quelqu'un qui n'en entendait jamais parler, et la trace disait « consentement ».
        //
        // Tout le module est cohérent sur la nature de cet identifiant : l'émetteur écrit
        // `$reservation->getOrganisateur()?->getId()`, et la comparaison anti-IDOR de l'acceptation
        // en dépend. C'était donc cet appel-ci, et lui seul, qu'il fallait corriger — même saut que
        // `RecoverySubjectCustomerResolver` fait déjà côté relance des recettes.
        $beneficiaire = $this->em->getRepository(Beneficiaire::class)->find($entry->getBeneficiaryId());
        $idClient = $beneficiaire?->getClient()?->getId();

        if ($idClient === null) {
            // Ne pas pouvoir joindre quelqu'un est un fait d'exploitation : le taire reproduirait le
            // défaut qu'on vient de corriger, un cran plus loin. La promotion reste acquise — on ne
            // la défait pas parce qu'un courriel ne part pas (D7).
            $this->logger?->warning('smart_flow.promotion.client_introuvable', [
                'entry' => (string) $entry->getId(),
                'beneficiaire' => (string) $entry->getBeneficiaryId(),
                'consequence' => 'la personne promue n\'a pas été prévenue',
            ]);

            return $proposal;
        }

        try {
            // ⚠ BASE LÉGALE À CONFIRMER PAR claude-A : `Consentement` par défaut (le plus strict), même
            // question que `RescheduleRequestedListener` pour une proposition de report après no-show.
            $this->notifier->notify(new ClientNotification(
                $idClient,
                NotificationChannel::Email,
                'smart_flow.waitlist_promoted',
                [
                    'entryId' => $entry->getId()->toRfc4122(),
                    'promotedProposalRef' => $entry->getPromotedProposalRef()?->toRfc4122(),
                ],
                $occurredAt,
                'smart_flow',
                NotificationBasis::Consentement,
            ));
        } catch (\Throwable $error) {
            // Best-effort (D7) : un appelant console (`ExpireSlotWaitlistPromotionsCommand`) n'est pas
            // enveloppé par le try/catch d'un listener — la promotion elle-même (déjà persistée) ne doit
            // jamais échouer parce que la notification a échoué.
            $this->logger?->error('smart_flow.notification.failed', [
                'entry' => (string) $entry->getId(),
                'reason' => $error->getMessage(),
            ]);
        }

        return $proposal;
    }
}
