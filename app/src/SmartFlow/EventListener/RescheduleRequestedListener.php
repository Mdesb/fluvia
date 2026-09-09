<?php

declare(strict_types=1);

namespace App\SmartFlow\EventListener;

use App\Crm\Entity\Beneficiaire;
use App\Organisation\Entity\Etablissement;
use App\Platform\Event\DomainEvent;
use App\Platform\Notification\ClientNotification;
use App\Platform\Notification\ClientNotifierInterface;
use App\Platform\Notification\NotificationBasis;
use App\Platform\Notification\NotificationChannel;
use App\SmartFlow\Entity\RescheduleProposal;
use App\SmartFlow\Enum\RescheduleProposalStatus;
use App\SmartFlow\Service\CompatibleSlotFinder;
use App\SmartFlow\Service\ReservationSlotReader;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Abonné à `booking.reschedule_requested` (RG-SF-08..12, plan-smart-flow.md T4) — crée une
 * `RescheduleProposal` en `searching`, tente `CompatibleSlotFinder` (RG-SF-09), notifie le client via le
 * port transverse `App\Platform\Notification\ClientNotifierInterface` si un créneau compatible est
 * trouvé (RG-SF-10, passe alors en `proposed`) — remplace l'ancien port propre à Smart Flow
 * (`App\SmartFlow\Port\ClientNotificationInterface`, supprimé) : le consentement RGPD est désormais
 * imposé par le décorateur du port transverse, plus par ce module.
 *
 * **Best-effort, par obligation (D7, patron `App\Platform\Event\Legacy\LegacyEventBridge`).** Ce
 * listener s'exécute dans la **même transaction PHP/Doctrine** que l'émetteur
 * (`AnnulerReservationProcessor`/`BasculerNoShowCommand`, bus synchrone) : le bus propage toute
 * exception non gérée à l'émetteur (RG-PLAT-05). Un no-show avec crédit restitué qui échoue à générer
 * une proposition de report ne doit **jamais** faire échouer le constat de no-show/l'annulation
 * eux-mêmes — toute erreur est donc capturée et journalisée (`smart_flow.listener.failed`), jamais
 * propagée.
 */
final class RescheduleRequestedListener implements EventSubscriberInterface
{
    /** ⚠ HYPOTHÈSE non chiffrée par une source produit (plan §0.7) — à rendre paramétrable par établissement (I2+). */
    private const SEARCH_WINDOW_DAYS = 14;

    /** ⚠ HYPOTHÈSE non chiffrée par une source produit (plan §0.7) — à rendre paramétrable par établissement (I2+). */
    private const PROPOSAL_EXPIRATION_DAYS = 30;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReservationSlotReader $slotReader,
        private readonly CompatibleSlotFinder $finder,
        private readonly ClientNotifierInterface $notifier,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /** @return array<string, string> */
    public static function getSubscribedEvents(): array
    {
        return [
            'booking.reschedule_requested' => 'onRescheduleRequested',
        ];
    }

    public function onRescheduleRequested(DomainEvent $event): void
    {
        try {
            $this->handle($event);
        } catch (\Throwable $error) {
            // Jamais de propagation : l'action métier qui a déclenché ce listener (constat de no-show,
            // annulation tardive) doit aboutir même si Smart Flow échoue à créer sa proposition.
            $this->logger?->error('smart_flow.listener.failed', [
                'event' => $event->name->value,
                'reason' => $error->getMessage(),
            ]);
        }
    }

    private function handle(DomainEvent $event): void
    {
        $establishment = $this->em->getRepository(Etablissement::class)->find($event->tenant->establishmentId);
        if (!$establishment instanceof Etablissement) {
            return;
        }

        $customerId = $this->uuid($event->payload['customerId'] ?? null);
        $reservationRef = $this->uuid($event->payload['reservationRef'] ?? null);
        $slotId = $this->uuid($event->payload['slotId'] ?? null);
        // `droitId` (D5 : nom du payload côté App\Reservation, hors périmètre de ce lot) — porté ici
        // par `entitlementRef` (§ docblock de `RescheduleProposal`).
        $entitlementRef = $this->uuid($event->payload['droitId'] ?? null);

        if ($customerId === null || $reservationRef === null || $slotId === null || $entitlementRef === null) {
            return; // Payload incomplet : rien à faire (best-effort, pas d'exception).
        }

        $now = new \DateTimeImmutable();

        $proposal = new RescheduleProposal();
        $proposal->setEstablishment($establishment)
            ->setOriginReservationRef($reservationRef)
            ->setOriginSlotId($slotId)
            ->setCustomerId($customerId)
            ->setEntitlementRef($entitlementRef)
            ->setStatus(RescheduleProposalStatus::Searching)
            ->setExpiresAt($now->modify(sprintf('+%d days', self::PROPOSAL_EXPIRATION_DAYS)));

        $origin = $this->slotReader->snapshotCreneau($slotId, $event->tenant->establishmentId);
        if ($origin !== null) {
            $candidates = $this->slotReader->findCandidateSlots(
                $event->tenant->establishmentId,
                $now,
                $now->modify(sprintf('+%d days', self::SEARCH_WINDOW_DAYS)),
            );
            $compatible = $this->finder->selectCompatible($origin, $candidates);
            $proposal->setLastSearchAttemptAt($now);

            if ($compatible !== null) {
                $proposal->setProposedSlotId($compatible->id)->setStatus(RescheduleProposalStatus::Proposed);
            }
        }
        // $origin === null (RG-SF-16, slot hors périmètre/introuvable) : la proposition est tout de
        // même créée en `searching` — rien à proposer aujourd'hui, mais RG-SF-11 exige qu'aucun crédit
        // restitué ne parte sans proposition traçable, même en attente.

        $this->em->persist($proposal);
        $this->em->flush();

        if (RescheduleProposalStatus::Proposed === $proposal->getStatus()) {
            // ⚠ BASE LÉGALE À CONFIRMER PAR claude-A : `Consentement` par défaut (le plus strict) —
            // une proposition de report après no-show est peut-être un message dû au titre du contrat
            // (`Contractuelle`, cf. `App\Subscription\EventListener\EnvoyerCourrielDeBienvenue`), à
            // trancher une fois le fondement RGPD de Smart Flow arbitré.
            // ⚠ LE CLIENT DU BÉNÉFICIAIRE, PAS LE BÉNÉFICIAIRE — jumeau du défaut corrigé dans
            // `SlotWaitlistPromotionService`. `customerId` porte l'identifiant rendu par
            // `getOrganisateur()?->getId()` ; `ClientNotification` attend un `Client`, et
            // `ConsentGatedNotifier` rend `Refusee` s'il n'en trouve pas — indiscernable d'un refus
            // de consentement. La proposition de report n'atteignait donc personne.
            $beneficiaire = $this->em->getRepository(Beneficiaire::class)->find($proposal->getCustomerId());
            $idClient = $beneficiaire?->getClient()?->getId();

            if ($idClient === null) {
                // Best-effort comme le reste de ce listener, mais jamais muet : une proposition
                // qu'on ne peut annoncer à personne est un fait d'exploitation.
                $this->logger?->warning('smart_flow.reschedule.client_introuvable', [
                    'proposal' => (string) $proposal->getId(),
                    'beneficiaire' => (string) $proposal->getCustomerId(),
                    'consequence' => 'la proposition de report n\'a été annoncée à personne',
                ]);

                return;
            }

            $this->notifier->notify(new ClientNotification(
                $idClient,
                NotificationChannel::Email,
                'smart_flow.reschedule_proposed',
                [
                    'proposalId' => $proposal->getId()->toRfc4122(),
                    'proposedSlotId' => $proposal->getProposedSlotId()?->toRfc4122(),
                ],
                // D37 : l'instant métier est celui de l'événement source (`booking.reschedule_requested`),
                // jamais l'heure d'exécution de ce listener.
                $event->occurredAt,
                'smart_flow',
                NotificationBasis::Consentement,
            ));
        }
    }

    private function uuid(mixed $value): ?Uuid
    {
        return \is_string($value) && Uuid::isValid($value) ? Uuid::fromString($value) : null;
    }
}
