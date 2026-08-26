<?php

declare(strict_types=1);

namespace App\Caution\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Caution\Entity\GrilleRetenue;
use App\Securite\Service\ContexteEtablissement;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * D41 — l'établissement d'une `GrilleRetenue` créée par l'API vient de la **session serveur**, jamais
 * du corps de la requête. Même patron que `App\Reservation\State\EstablishmentStampProcessor`
 * (`claude-G`), recopié plutôt qu'importé (D2).
 *
 * **La grille de retenue dit combien on garde sur la caution d'un client** en cas de casse, de retard
 * ou de perte. Laisser l'appelant choisir l'établissement de rattachement revenait à laisser écrire un
 * barème applicable chez quelqu'un d'autre — et une retenue se constate au moment où le client réclame
 * son argent, c'est-à-dire trop tard pour discuter du barème.
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
        if ($data instanceof GrilleRetenue && null === $data->getEtablissement()) {
            $etablissement = $this->contexte->etablissementActif();
            if (null === $etablissement) {
                throw new UnprocessableEntityHttpException(
                    'Aucun établissement actif : impossible de rattacher cette création (D41).',
                );
            }

            $data->setEtablissement($etablissement);
        }

        return $this->persist->process($data, $operation, $uriVariables, $context);
    }
}
