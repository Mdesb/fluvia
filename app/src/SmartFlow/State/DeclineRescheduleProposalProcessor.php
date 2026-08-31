<?php

declare(strict_types=1);

namespace App\SmartFlow\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\SmartFlow\Entity\RescheduleProposal;
use App\SmartFlow\Enum\RescheduleProposalStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * `POST /smart-flow/reschedule-proposals/{id}/decline` (RG-SF-12) — symétrique de `/accept`, ne prend
 * aucun corps : statut → `expired` immédiat. Aucune écriture dans `App\Reservation`.
 *
 * Garde d'état (même patron que `App\RevenueRecovery\Service\RecoveryEngine::stopManually()`) : seule
 * une proposition dans un état **non terminal** (`searching`/`proposed`) peut être déclinée — une
 * proposition déjà `confirmed` ne doit jamais basculer `expired` (elle a déjà donné lieu à une nouvelle
 * réservation), une proposition déjà `expired` est un no-op sans effet observable ; les deux cas
 * renvoient 409.
 *
 * @implements ProcessorInterface<mixed, RescheduleProposal>
 */
final class DeclineRescheduleProposalProcessor implements ProcessorInterface
{
    private const NON_TERMINAL_STATUSES = [RescheduleProposalStatus::Searching, RescheduleProposalStatus::Proposed];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RescheduleProposal
    {
        \assert($data instanceof RescheduleProposal);

        if (!\in_array($data->getStatus(), self::NON_TERMINAL_STATUSES, true)) {
            throw new ConflictHttpException('smart_flow.error.reschedule_proposal_already_terminal');
        }

        $data->setStatus(RescheduleProposalStatus::Expired);
        $this->em->flush();

        return $data;
    }
}
