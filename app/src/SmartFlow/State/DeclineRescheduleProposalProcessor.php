<?php

declare(strict_types=1);

namespace App\SmartFlow\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\SmartFlow\Entity\RescheduleProposal;
use App\SmartFlow\Enum\RescheduleProposalStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * `POST /smart-flow/reschedule-proposals/{id}/decline` (RG-SF-12) — symétrique de `/accept`, ne prend
 * aucun corps : statut → `expired` immédiat. Aucune écriture dans `App\Reservation`.
 *
 * @implements ProcessorInterface<mixed, RescheduleProposal>
 */
final class DeclineRescheduleProposalProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RescheduleProposal
    {
        \assert($data instanceof RescheduleProposal);

        $data->setStatus(RescheduleProposalStatus::Expired);
        $this->em->flush();

        return $data;
    }
}
