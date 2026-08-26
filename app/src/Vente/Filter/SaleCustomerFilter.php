<?php

declare(strict_types=1);

namespace App\Vente\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Uid\Uuid;

/**
 * Filtre `?client=<uuid>` sur l'historique des ventes (D48).
 *
 * **Pourquoi un filtre dédié plutôt qu'un `SearchFilter`.** L'ordre m'annonçait « trois attributs sur
 * une ligne » ; ce fut deux. `Vente::client` n'est pas une relation vers `Crm\Entity\Client` mais une
 * **référence libre** (`?Uuid`, même patron que `produitRef`/`reservationRef` ailleurs). Un
 * `SearchFilter` posé dessus ne lève rien et ne filtre rien : il rend une liste **vide**, parce que la
 * valeur n'est pas convertie vers le type Doctrine `uuid` (binaire) au moment de la liaison. Vérifié,
 * pas supposé — c'est ce que le test a montré avant ce fichier.
 *
 * C'est la même famille de piège que le paramètre d'entité non lié rencontré sur la jauge des
 * créneaux : un identifiant de type personnalisé passé tel quel produit une requête **silencieusement
 * fausse**, pas une erreur. Ici la conséquence serait pire qu'une liste vide au hasard : un caissier
 * qui cherche les ventes d'un client et n'en trouve aucune en conclut qu'il n'en a pas.
 *
 * Premier filtre sur mesure du dépôt. Il reste minimal à dessein : une seule propriété, une seule
 * comparaison, et le typage explicite qui manquait.
 */
final class SaleCustomerFilter extends AbstractFilter
{
    protected function filterProperty(
        string $property,
        mixed $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        if ($property !== 'client' || !\is_string($value) || !Uuid::isValid($value)) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $parametre = $queryNameGenerator->generateParameterName('client');

        $queryBuilder
            ->andWhere(sprintf('%s.client = :%s', $alias, $parametre))
            ->setParameter($parametre, Uuid::fromString($value), 'uuid');
    }

    /** @return array<string, array<string, mixed>> */
    public function getDescription(string $resourceClass): array
    {
        return [
            'client' => [
                'property' => 'client',
                'type' => 'string',
                'required' => false,
                'description' => 'Ventes rattachées à ce client (UUID exact).',
            ],
        ];
    }
}
