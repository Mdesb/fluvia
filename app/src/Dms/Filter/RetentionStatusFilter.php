<?php

declare(strict_types=1);

namespace App\Dms\Filter;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Dms\Entity\Document;
use Doctrine\ORM\QueryBuilder;

/**
 * Filtre custom `?retentionStatus=none|active|expired` (plan-dms.md §2.1) — `retentionStatus` n'est pas
 * une colonne (RG-DMS-12 : calculé, jamais figé), traduit en prédicat SQL sur
 * `retentionPolicy`/`retainUntil` :
 * - `none` -> `retentionPolicy IS NULL`
 * - `active` -> `retainUntil IS NOT NULL AND retainUntil > CURRENT_DATE()`
 * - `expired` -> `retainUntil IS NOT NULL AND retainUntil <= CURRENT_DATE()`
 */
final class RetentionStatusFilter implements FilterInterface
{
    public function apply(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        if ($resourceClass !== Document::class) {
            return;
        }

        $valeur = $context['filters']['retentionStatus'] ?? null;
        if (!\is_string($valeur)) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];

        match ($valeur) {
            'none' => $queryBuilder->andWhere(sprintf('%s.retentionPolicy IS NULL', $alias)),
            'active' => $queryBuilder->andWhere(sprintf(
                '%s.retainUntil IS NOT NULL AND %s.retainUntil > CURRENT_DATE()',
                $alias,
                $alias,
            )),
            'expired' => $queryBuilder->andWhere(sprintf(
                '%s.retainUntil IS NOT NULL AND %s.retainUntil <= CURRENT_DATE()',
                $alias,
                $alias,
            )),
            default => null,
        };
    }

    public function getDescription(string $resourceClass): array
    {
        if ($resourceClass !== Document::class) {
            return [];
        }

        return [
            'retentionStatus' => [
                'property' => null,
                'type' => 'string',
                'required' => false,
                'description' => 'Statut de rétention calculé (RG-DMS-12) : none|active|expired.',
                'schema' => ['type' => 'string', 'enum' => ['none', 'active', 'expired']],
            ],
        ];
    }
}
