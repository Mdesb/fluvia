<?php

declare(strict_types=1);

namespace App\Recouvrement\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Recouvrement\Entity\IncidentImpaye;
use App\Recouvrement\Service\DebtorNameRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * SERT LES IMPAYÉS AVEC LE NOM DE CELUI QUI DOIT.
 *
 * ⚠ IL DÉCORE, IL NE REMPLACE PAS. Le fournisseur Doctrine porte le cloisonnement par établissement,
 * les filtres et la pagination. En réécrire la requête pour ajouter une colonne, c'est réécrire ces
 * trois garanties — et en oublier une ne se verrait pas : l'écran rendrait simplement plus de lignes
 * qu'il ne devrait, avec un beau nom sur chacune.
 *
 * ⚠ L'ENRICHISSEMENT MUTE LES OBJETS RENDUS ET REND LE RÉSULTAT INTACT. Reconstruire une liste
 * perdrait le `Paginator` — donc le nombre total, donc la pagination de l'écran.
 *
 * ── POURQUOI CE N'EST PAS UN CHAMP DE LA BASE ──────────────────────────────────────────────────
 *
 * Le nom appartient au client, pas à l'impayé. Le copier dans `IncidentImpaye` à la création
 * figerait l'orthographe du jour : un client renommé ou fusionné garderait son ancien nom sur ses
 * impayés ouverts, et l'agent chercherait une fiche qui ne s'appelle plus comme ça. On résout à la
 * lecture, à chaque lecture.
 *
 * @implements ProviderInterface<IncidentImpaye>
 */
final class UnpaidIncidentProvider implements ProviderInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.collection_provider')]
        private readonly ProviderInterface $collection,
        #[Autowire(service: 'api_platform.doctrine.orm.state.item_provider')]
        private readonly ProviderInterface $item,
        private readonly DebtorNameRegistry $noms,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $resultat = $operation instanceof CollectionOperationInterface
            ? $this->collection->provide($operation, $uriVariables, $context)
            : $this->item->provide($operation, $uriVariables, $context);

        if ($resultat instanceof IncidentImpaye) {
            $this->nommer($resultat);

            return $resultat;
        }

        if (is_iterable($resultat)) {
            foreach ($resultat as $incident) {
                if ($incident instanceof IncidentImpaye) {
                    $this->nommer($incident);
                }
            }
        }

        return $resultat;
    }

    private function nommer(IncidentImpaye $incident): void
    {
        $incident->setNomRedevable(
            $this->noms->nameFor($incident->getTypeRedevable(), $incident->getReferenceRedevable()),
        );
    }
}
