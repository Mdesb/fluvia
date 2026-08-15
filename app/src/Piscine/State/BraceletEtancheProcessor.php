<?php

declare(strict_types=1);

namespace App\Piscine\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Acces\Enum\TypeSupport;
use App\Piscine\Entity\BraceletEtanche;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST d'un `BraceletEtanche` (RG-PISC-04, CA-10) : refuse tout `Support` dont le type n'est pas RFID.
 *
 * @implements ProcessorInterface<BraceletEtanche, BraceletEtanche>
 */
final class BraceletEtancheProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<BraceletEtanche, BraceletEtanche> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persistProcessor,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        \assert($data instanceof BraceletEtanche);

        if ($data->getSupport()?->getType() !== TypeSupport::Rfid) {
            throw new UnprocessableEntityHttpException('RG-PISC-04 : le bracelet étanche doit référencer un support de type RFID.');
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
