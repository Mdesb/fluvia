<?php

declare(strict_types=1);

namespace App\RevenueRecovery\Service;

use App\Crm\Entity\Client;
use App\Crm\Enum\CanalConsentement;
use App\Crm\Service\ConsentementResolver;
use App\Organisation\Entity\Etablissement;
use App\Platform\Event\DomainEvent;
use App\RevenueRecovery\Entity\RecoveryAttempt;
use App\RevenueRecovery\Entity\RecoveryCase;
use App\RevenueRecovery\Entity\RecoverySequence;
use App\RevenueRecovery\Enum\RecoveryAttemptStatus;
use App\RevenueRecovery\Enum\RecoveryCaseStatus;
use App\RevenueRecovery\Enum\RecoveryChannel;
use App\RevenueRecovery\Enum\RecoveryTriggerType;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Machine à états du module (plan-revenue-recovery.md §0.7/§0.9, patron
 * `App\Recouvrement\Service\MoteurRecouvrementHandler` — répliqué, jamais importé, D2). Point d'entrée
 * unique de tout abonné d'événement (`handle()`/`resolve()`) et de toute action HTTP volontaire
 * (`stopManually()`), pour que RG-RR-02/RG-RR-04/RG-RR-05 ne soient implémentées qu'une seule fois.
 *
 * RG-RR-06 (invariant central, test dédié `RevenueRecoveryAccessInvariantTest`) : cette classe ne
 * référence, n'injecte ni n'appelle **aucun** service du domaine accès (`App\Securite\Entity\DroitAcces`
 * ou équivalent) — `RevenueRecovery` n'écrit jamais sur l'accès.
 */
class RecoveryEngine
{
    /** Code de saut le plus fréquent (RG-RR-03) — les autres skip reasons restent libres (`skipReason` n'est pas une énumération fermée). */
    public const SKIP_REASON_NO_CONSENT = 'skipped_no_consent';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RecoverySubjectCustomerResolver $customerResolver,
        private readonly ConsentementResolver $consentementResolver,
        private readonly RecoveryAttemptMailer $mailer,
    ) {
    }

    /**
     * Point d'entrée unique des abonnés d'événement déclencheurs (§0.7 du plan). RG-RR-02 : sans
     * `RecoverySequence` active pour `(establishment, triggerType)`, **aucun** `RecoveryCase` n'est créé
     * — dégradation propre, jamais une erreur. Idempotence (§11 spec, ⚠ HYPOTHÈSE) : un seul
     * `RecoveryCase` actif à la fois par `(establishment, triggerType, subjectType, subjectRef)` — une
     * seconde occurrence pendant qu'un dossier est déjà `Active` renvoie ce dossier existant, n'en
     * rouvre jamais un second.
     */
    public function handle(DomainEvent $event, RecoveryTriggerType $triggerType, ?int $amountCents = null): ?RecoveryCase
    {
        $establishment = $this->em->getRepository(Etablissement::class)->find($event->tenant->establishmentId);
        if (!$establishment instanceof Etablissement) {
            return null;
        }

        $sequence = $this->em->getRepository(RecoverySequence::class)->findOneBy([
            'establishment' => $establishment,
            'triggerType' => $triggerType,
        ]);
        if (!$sequence instanceof RecoverySequence || !$sequence->isActive()) {
            // RG-RR-02 : inactif par défaut tant qu'aucune séquence n'est explicitement activée.
            return null;
        }

        $subjectType = $event->subject->type;
        $subjectRef = $event->subject->id;

        $existant = $this->em->getRepository(RecoveryCase::class)->findOneBy([
            'establishment' => $establishment,
            'triggerType' => $triggerType,
            'subjectType' => $subjectType,
            'subjectRef' => $subjectRef,
            'status' => RecoveryCaseStatus::Active,
        ]);
        if ($existant instanceof RecoveryCase) {
            return $existant;
        }

        $now = new \DateTimeImmutable();
        $case = new RecoveryCase();
        $case->setEstablishment($establishment)
            ->setTriggerType($triggerType)
            ->setSubjectType($subjectType)
            ->setSubjectRef($subjectRef)
            ->setAmountCents($amountCents)
            ->setStatus(RecoveryCaseStatus::Active)
            ->setSequence($sequence)
            ->setOpenedAt($now);

        $this->em->persist($case);

        // RG-RR-08 : au plus min(count(steps), maxAttempts) tentatives, toutes calculées depuis
        // `openedAt` (« J+1, J+3 » — délais comptés depuis le fait déclencheur, CA-1 US-RR-01).
        $steps = $sequence->getSteps();
        $limite = min(\count($steps), max(0, $sequence->getMaxAttempts()));
        for ($i = 0; $i < $limite; ++$i) {
            $step = $steps[$i];
            $delayDays = \is_array($step) && \is_numeric($step['delayDays'] ?? null) ? (int) $step['delayDays'] : 0;
            $channel = \is_array($step) && \is_string($step['channel'] ?? null) && RecoveryChannel::tryFrom($step['channel']) !== null
                ? RecoveryChannel::from($step['channel'])
                : RecoveryChannel::Email;

            $tentative = new RecoveryAttempt();
            $tentative->setStepIndex($i)
                ->setScheduledAt($now->modify(sprintf('+%d days', $delayDays)))
                ->setChannel($channel)
                ->setStatus(RecoveryAttemptStatus::Pending);
            $case->addAttempt($tentative); // maintient le côté inverse (inclut setRecoveryCase)
            $this->em->persist($tentative);
        }

        $this->em->flush();

        // TODO(claude-A catalogue) : émettre `revenue_recovery.case_opened` une fois les 5 événements
        // revenue_recovery.* ajoutés à COORDINATION/CONTRACT/catalogue-evenements.md (T9, différé — cf.
        // consignes de ce lot, émission volontairement absente en I1).

        return $case;
    }

    /**
     * Arrêt automatique sur événement de résolution (RG-RR-04, §0.7 du plan) : clôt tout `RecoveryCase`
     * actif correspondant à `(establishment, subjectType, subjectRef)`, annule les `RecoveryAttempt`
     * `Pending` restantes. Retourne le nombre de dossiers effectivement résolus (0 = rien à faire,
     * dégradation propre).
     */
    public function resolve(Uuid $establishmentId, string $subjectType, string $subjectRef): int
    {
        /** @var list<RecoveryCase> $cases */
        $cases = $this->em->getRepository(RecoveryCase::class)->createQueryBuilder('c')
            ->andWhere('IDENTITY(c.establishment) = :establishment')
            ->andWhere('c.subjectType = :subjectType')
            ->andWhere('c.subjectRef = :subjectRef')
            ->andWhere('c.status = :active')
            ->setParameter('establishment', $establishmentId, 'uuid')
            ->setParameter('subjectType', $subjectType)
            ->setParameter('subjectRef', $subjectRef)
            ->setParameter('active', RecoveryCaseStatus::Active->value)
            ->getQuery()
            ->getResult();

        if ([] === $cases) {
            return 0;
        }

        $now = new \DateTimeImmutable();
        foreach ($cases as $case) {
            $case->setStatus(RecoveryCaseStatus::Resolved)->setResolvedAt($now);
            foreach ($case->getAttempts() as $tentative) {
                if (RecoveryAttemptStatus::Pending === $tentative->getStatus()) {
                    $tentative->setStatus(RecoveryAttemptStatus::Cancelled);
                }
            }
            // TODO(claude-A catalogue) : émettre `revenue_recovery.case_resolved` (T9, différé).
        }

        $this->em->flush();

        return \count($cases);
    }

    /** Arrêt manuel — motif obligatoire (RG-RR-05, même exigence que `RG-SOCLE-07`). */
    public function stopManually(RecoveryCase $case, Utilisateur $agent, string $reason): RecoveryCase
    {
        if ('' === trim($reason)) {
            throw new UnprocessableEntityHttpException('revenue_recovery.error.stop_reason_required');
        }
        if (RecoveryCaseStatus::Active !== $case->getStatus()) {
            throw new ConflictHttpException('revenue_recovery.error.case_already_closed');
        }

        $case->setStatus(RecoveryCaseStatus::Stopped)
            ->setStoppedAt(new \DateTimeImmutable())
            ->setStopReason($reason)
            ->setStoppedBy($agent);

        foreach ($case->getAttempts() as $tentative) {
            if (RecoveryAttemptStatus::Pending === $tentative->getStatus()) {
                $tentative->setStatus(RecoveryAttemptStatus::Cancelled);
            }
        }

        $this->em->flush();

        // TODO(claude-A catalogue) : émettre `revenue_recovery.case_stopped` (T9, différé).

        return $case;
    }

    /**
     * Tâche planifiée `revenue-recovery:attempts:send` (RG-RR-03, cas limite §11 spec — vérifie l'état
     * actif de la séquence **à l'exécution**, pas seulement à la programmation). Traite toute
     * `RecoveryAttempt` `Pending` échue, dans l'ordre d'échéance.
     *
     * @return array{sent: int, skipped: int, cancelled: int, failed: int}
     */
    public function sendDueAttempts(?\DateTimeImmutable $at = null): array
    {
        $maintenant = $at ?? new \DateTimeImmutable();

        /** @var list<RecoveryAttempt> $tentativesDues */
        $tentativesDues = $this->em->getRepository(RecoveryAttempt::class)->createQueryBuilder('a')
            ->andWhere('a.status = :pending')
            ->andWhere('a.scheduledAt <= :maintenant')
            ->setParameter('pending', RecoveryAttemptStatus::Pending->value)
            ->setParameter('maintenant', $maintenant)
            ->orderBy('a.scheduledAt', 'ASC')
            ->getQuery()
            ->getResult();

        $compteurs = ['sent' => 0, 'skipped' => 0, 'cancelled' => 0, 'failed' => 0];

        foreach ($tentativesDues as $tentative) {
            $case = $tentative->getRecoveryCase();
            if (!$case instanceof RecoveryCase || RecoveryCaseStatus::Active !== $case->getStatus()) {
                // Dossier résolu/arrêté entre la programmation et l'échéance : rien à envoyer.
                $tentative->setStatus(RecoveryAttemptStatus::Cancelled);
                ++$compteurs['cancelled'];
                continue;
            }

            $sequence = $case->getSequence();
            if (!$sequence instanceof RecoverySequence || !$sequence->isActive()) {
                // Séquence désactivée entre programmation et échéance (cas limite §11 spec).
                $tentative->setStatus(RecoveryAttemptStatus::Cancelled);
                ++$compteurs['cancelled'];
                $this->maybeExhaust($case);
                continue;
            }

            if (!$this->isChannelConsented($case, $tentative->getChannel())) {
                $tentative->setStatus(RecoveryAttemptStatus::Skipped)->setSkipReason(self::SKIP_REASON_NO_CONSENT);
                ++$compteurs['skipped'];
                $this->maybeExhaust($case);
                continue;
            }

            try {
                $envoyee = $this->mailer->send($case, $tentative);
            } catch (\Throwable) {
                // Panne du transport mail (réseau, fournisseur…) : ne doit jamais interrompre le
                // traitement des autres tentatives échues dans le même passage de la tâche planifiée.
                $tentative->setStatus(RecoveryAttemptStatus::Failed);
                ++$compteurs['failed'];
                $this->maybeExhaust($case);
                continue;
            }
            if ($envoyee) {
                $tentative->setStatus(RecoveryAttemptStatus::Sent)->setSentAt($maintenant);
                ++$compteurs['sent'];
                // TODO(claude-A catalogue) : émettre `revenue_recovery.attempt_sent` (T9, différé).
            } else {
                // Aucun contact e-mail connu : marqué « sauté » (même traitement que l'absence de
                // consentement, RG-RR-03 — pas une erreur, pas de propagation).
                $tentative->setStatus(RecoveryAttemptStatus::Skipped)->setSkipReason(self::SKIP_REASON_NO_CONSENT);
                ++$compteurs['skipped'];
                // TODO(claude-A catalogue) : émettre `revenue_recovery.attempt_skipped` (T9, différé).
            }
            $this->maybeExhaust($case);
        }

        $this->em->flush();

        return $compteurs;
    }

    private function isChannelConsented(RecoveryCase $case, RecoveryChannel $channel): bool
    {
        $clientId = $this->customerResolver->resolveCustomerId($case->getSubjectType(), $case->getSubjectRef());
        if ($clientId === null) {
            // Pas de client identifiable : échec fermé (RG-RR-03), jamais un envoi à l'aveugle.
            return false;
        }

        $client = $this->em->getRepository(Client::class)->find($clientId);
        if (!$client instanceof Client) {
            return false;
        }

        $canal = match ($channel) {
            RecoveryChannel::Email => CanalConsentement::Email,
        };

        return $this->consentementResolver->estExploitable($client, $canal);
    }

    /** RG-RR-08 : plus aucune tentative `Pending` pour ce dossier -> `Exhausted` (sauf s'il vient d'être clos autrement). */
    private function maybeExhaust(RecoveryCase $case): void
    {
        if (RecoveryCaseStatus::Active !== $case->getStatus()) {
            return;
        }

        foreach ($case->getAttempts() as $tentative) {
            if (RecoveryAttemptStatus::Pending === $tentative->getStatus()) {
                return;
            }
        }

        $case->setStatus(RecoveryCaseStatus::Exhausted);
    }
}
