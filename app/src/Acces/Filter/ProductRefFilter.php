<?php

declare(strict_types=1);

namespace App\Acces\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Uid\Uuid;

/**
 * Filtre `?productRef=<uuid>` sur les déclarations de zone d'un produit.
 *
 * ── POURQUOI PAS UN `SearchFilter` ──────────────────────────────────────────────────────────────
 *
 * `ProductAccessZone::$productRef` n'est pas une relation Doctrine mais une **référence libre** :
 * `App\Acces` ne dépend pas de `App\Offre` (D2). Un `SearchFilter` posé sur une colonne `uuid` nue ne
 * lève rien et ne filtre rien — il rend une liste VIDE, parce que la valeur n'est jamais convertie
 * vers le type binaire au moment de la liaison (D58).
 *
 * La conséquence serait pire qu'une liste vide au hasard : l'écran de configuration d'un produit
 * afficherait « Aucune restriction : ce produit ouvre toutes les zones » sur un produit qui en a
 * déclaré trois. L'exploitant croirait avoir tout ouvert, et ré-ouvrirait pour de bon.
 *
 * Même patron que `App\Vente\Filter\SaleCustomerFilter`, écrit pour la même raison sur `Vente::client`.
 * Le garde-fou des références libres refuse le `SearchFilter` précisément pour envoyer ici.
 */
final class ProductRefFilter extends AbstractFilter
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
        if ($property !== 'productRef' || !\is_string($value) || !Uuid::isValid($value)) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $parametre = $queryNameGenerator->generateParameterName('productRef');

        $queryBuilder
            ->andWhere(sprintf('%s.productRef = :%s', $alias, $parametre))
            // ⚠ Le troisième argument est tout l'objet de ce fichier.
            ->setParameter($parametre, Uuid::fromString($value), 'uuid');
    }

    /** @return array<string, array<string, mixed>> */
    public function getDescription(string $resourceClass): array
    {
        return [
            'productRef' => [
                'property' => 'productRef',
                'type' => 'string',
                'required' => false,
                'description' => 'Zones déclarées pour ce produit (UUID exact).',
            ],
        ];
    }
}
