<?php

declare(strict_types=1);

namespace App\Compta\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Compta\Entity\DeclarationEReporting;
use App\Compta\Port\PdpInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * POST /compta/e-reporting/{id}/transmettre : dépôt via le port PDP (⚠ HYPOTHÈSE canal technique).
 *
 * @implements ProcessorInterface<DeclarationEReporting, DeclarationEReporting>
 */
final class TransmettreEReportingProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PdpInterface $pdp,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DeclarationEReporting
    {
        \assert($data instanceof DeclarationEReporting);

        $data->setStatutEnvoi($this->pdp->deposer($data));
        $this->em->flush();

        return $data;
    }
}
