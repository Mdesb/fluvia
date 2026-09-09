<?php

declare(strict_types=1);

namespace App\Group\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Group\Entity\GroupGratuite;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Révoque une gratuité (DELETE) : **recrédite d'abord le contingent** (`consomme -= quantite`), puis
 * supprime la ligne. Sans cette recréditation, révoquer laisserait le contingent épuisé à tort.
 *
 * @implements ProcessorInterface<GroupGratuite, mixed>
 */
final class RevokeGratuiteProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.remove_processor')]
        private readonly ProcessorInterface $remove,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof GroupGratuite) {
            $contingent = $data->getContingent();
            if ($contingent !== null) {
                $contingent->setConsomme($contingent->getConsomme() - $data->getQuantite());
            }
        }

        return $this->remove->process($data, $operation, $uriVariables, $context);
    }
}
