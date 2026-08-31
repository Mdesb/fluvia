<?php

declare(strict_types=1);

namespace App\Project\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Project\Entity\Project;
use App\Securite\Service\ContexteEtablissement;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * D41 : l'etablissement d'un projet vient de la session serveur, jamais du corps.
 *
 * Meme patron que `App\Boutique\State\EstablishmentStampProcessor`, RECOPIE plutot qu'importe :
 * D2 interdit l'appel direct de module a module.
 *
 * Expose en ecriture, l'appelant choisirait dans quel etablissement atterrit son projet -- et
 * lirait ensuite, par la meme voie, le plan de charge d'un voisin.
 *
 * @implements ProcessorInterface<mixed, mixed>
 */
final class ProjectEstablishmentStampProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persist,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof Project && $data->getEstablishment() === null) {
            $etablissement = $this->contexte->etablissementActif();
            if ($etablissement === null) {
                throw new UnprocessableEntityHttpException(
                    'Aucun etablissement actif : impossible de rattacher ce projet (D41).',
                );
            }

            $data->setEstablishment($etablissement);
        }

        return $this->persist->process($data, $operation, $uriVariables, $context);
    }
}
