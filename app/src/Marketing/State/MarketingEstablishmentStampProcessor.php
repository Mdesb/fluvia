<?php

declare(strict_types=1);

namespace App\Marketing\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Marketing\Entity\Segment;
use App\Securite\Service\ContexteEtablissement;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * D41 — l'établissement d'un segment vient de la **session serveur**, jamais du corps.
 *
 * Même patron que les estampilleurs des autres modules, **recopié plutôt qu'importé** : D2 interdit
 * l'appel direct de module à module.
 *
 * **Ce qu'un champ écrivable aurait ouvert ici, et c'est pire qu'ailleurs.** Un segment est une
 * *description de clients*. Laisser l'appelant choisir l'établissement de rattachement lui
 * permettrait de créer un segment au nom d'un voisin, puis d'en lire l'aperçu — c'est-à-dire de
 * compter, et d'échantillonner, la clientèle de ce voisin.
 *
 * Le cloisonnement de la RÉSOLUTION (`SegmentResolver`) l'en empêcherait de toute façon : il borne
 * les clients au périmètre de l'appelant, pas à l'établissement du segment. Mais deux verrous valent
 * mieux qu'un quand ce qui est en jeu est un fichier de clients.
 *
 * @implements ProcessorInterface<mixed, mixed>
 */
final class MarketingEstablishmentStampProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persist,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof Segment && $data->getEstablishment() === null) {
            $etablissement = $this->contexte->etablissementActif();
            if ($etablissement === null) {
                // On ne devine pas : un segment rattaché au hasard apparaîtrait dans la liste d'un
                // établissement qui ne l'a jamais créé.
                throw new UnprocessableEntityHttpException(
                    'Aucun établissement actif : impossible de rattacher ce segment (D41).',
                );
            }

            $data->setEstablishment($etablissement);
        }

        return $this->persist->process($data, $operation, $uriVariables, $context);
    }
}
