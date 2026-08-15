<?php

declare(strict_types=1);

namespace App\Crm\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Crm\Entity\Famille;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Déduit `Famille.groupe` du `payeurPrincipal.groupe` à la création (même portée, §1 plan-crm.md) —
 * le champ n'est pas exposé en écriture directe (une famille suit toujours la portée de son payeur).
 *
 * @implements ProcessorInterface<Famille, Famille>
 */
final class FamilleEcritureProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<Famille, Famille> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persistProcessor,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        \assert($data instanceof Famille);

        if ($data->getGroupe() === null) {
            $data->setGroupe($data->getPayeurPrincipal()?->getGroupe());
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
