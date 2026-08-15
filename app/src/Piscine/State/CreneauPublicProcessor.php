<?php

declare(strict_types=1);

namespace App\Piscine\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Piscine\Entity\CreneauPublic;
use App\Piscine\Service\CreneauPublicHandler;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * POST/PATCH d'un `CreneauPublic` (CA-5/6/7) : applique les gardes métier puis délègue au persist
 * processor Doctrine standard (même pattern que `ProduitProcessor`, M1).
 *
 * @implements ProcessorInterface<CreneauPublic, CreneauPublic>
 */
final class CreneauPublicProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<CreneauPublic, CreneauPublic> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persistProcessor,
        private readonly CreneauPublicHandler $handler,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        \assert($data instanceof CreneauPublic);

        $this->handler->enregistrer($data);

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
