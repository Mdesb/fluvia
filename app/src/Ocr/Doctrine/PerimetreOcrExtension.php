<?php

declare(strict_types=1);

namespace App\Ocr\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Ocr\Entity\ExtractionAttempt;
use App\Ocr\Entity\OcrProviderConfig;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement multi-entités des ressources OCR (RG-SOCLE-05, plan-ocr.md §3) — copie stricte du
 * patron `App\Sepa\Doctrine\PerimetreSepaExtension`. `OcrProviderConfig`/`ExtractionAttempt` portent
 * directement leur `establishment` (pas de chaîne de jointure). Périmètre **dérivé serveur**
 * (`Security::getUser()` + `Affectation`), jamais un id transmis par le client (invariant noyau
 * commun #1) — échec fermé (403/404), jamais un filtre silencieux qui renverrait une liste vide sans
 * distinguer « vide » de « hors périmètre ».
 */
final class PerimetreOcrExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /** @var array<class-string, list<string>> Relations à joindre depuis la racine jusqu'à « establishment ». */
    private const CHAINES = [
        OcrProviderConfig::class => [],
        ExtractionAttempt::class => [],
    ];

    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $this->restreindre($queryBuilder, $resourceClass);
    }

    /**
     * @param array<string, mixed> $identifiers
     * @param array<string, mixed> $context
     */
    public function applyToItem(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        array $identifiers,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $this->restreindre($queryBuilder, $resourceClass);
    }

    private function restreindre(QueryBuilder $queryBuilder, string $resourceClass): void
    {
        if (!isset(self::CHAINES[$resourceClass])) {
            return;
        }
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        foreach (self::CHAINES[$resourceClass] as $i => $relation) {
            $nouvelAlias = 'ocr_perimetre_' . $i;
            $queryBuilder->innerJoin($alias . '.' . $relation, $nouvelAlias);
            $alias = $nouvelAlias;
        }

        $queryBuilder
            ->innerJoin(
                Affectation::class,
                'aff_perimetre_ocr',
                Join::WITH,
                sprintf(
                    'IDENTITY(aff_perimetre_ocr.etablissement) = IDENTITY(%s.establishment) AND IDENTITY(aff_perimetre_ocr.utilisateur) = :perimetre_ocr_utilisateur',
                    $alias,
                ),
            )
            ->setParameter('perimetre_ocr_utilisateur', $utilisateur->getId(), 'uuid')
            ->distinct();
    }
}
