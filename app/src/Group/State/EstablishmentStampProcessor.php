<?php

declare(strict_types=1);

namespace App\Group\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Group\Entity\GroupGratuiteContingent;
use App\Group\Entity\GroupProduct;
use App\Group\Entity\ParticipantGroup;
use App\Securite\Service\ContexteEtablissement;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * D41 — l'établissement d'un `ParticipantGroup` vient de la **session serveur**, jamais du corps de la
 * requête. Repris du patron `App\Reservation\State\EstablishmentStampProcessor` : l'estampillage se
 * fait ici, entre désérialisation et écriture, car un `Post` est en `read: false` et ne consulte aucun
 * fournisseur, et un `Assert\NotNull` sur le champ ferait échouer la création en 422 avant ce service.
 *
 * @implements ProcessorInterface<mixed, mixed>
 */
final class EstablishmentStampProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persist,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if (($data instanceof ParticipantGroup || $data instanceof GroupProduct || $data instanceof GroupGratuiteContingent) && $data->getEtablissement() === null) {
            $etablissement = $this->contexte->etablissementActif();
            if ($etablissement === null) {
                throw new UnprocessableEntityHttpException(
                    'Aucun établissement actif : impossible de rattacher cette création (D41).'
                );
            }
            $data->setEtablissement($etablissement);
        }

        return $this->persist->process($data, $operation, $uriVariables, $context);
    }
}
