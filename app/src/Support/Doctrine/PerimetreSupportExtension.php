<?php

declare(strict_types=1);

namespace App\Support\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use App\Securite\Service\ContexteEtablissement;
use App\Support\Entity\ArticleAide;
use App\Support\Entity\TicketSupport;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement multi-entités du back-office Support (RG-SOCLE-05, §4 plan-support.md), patron
 * `App\Personnel\Doctrine\PerimetrePersonnelExtension`.
 *
 * - `ArticleAide` (listing back-office, tous statuts) : visible si `portee=global`, **ou**
 *   `portee=local` et l'utilisateur possède une `Affectation` sur `etablissement` — appliqué **en
 *   plus** du filtrage droits/ciblage fait par le Voter/Provider public.
 * - `TicketSupport` : restreint selon la permission la plus large détenue —
 *   `administrer`/`traiter_ticket_n1`/`traiter_ticket_n2` → tous les tickets du périmètre
 *   d'affectation ; `lire_ticket_etablissement` → tickets de l'établissement actif uniquement ;
 *   sinon → `demandeur = utilisateur courant` (`lire_ticket_soi`).
 */
final class PerimetreSupportExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly ContexteEtablissement $contexte,
        private readonly CalculateurDroits $calculateur,
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
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return;
        }

        if ($resourceClass === ArticleAide::class) {
            $this->restreindreArticle($queryBuilder, $utilisateur);

            return;
        }

        if ($resourceClass === TicketSupport::class) {
            $this->restreindreTicket($queryBuilder, $utilisateur);
        }
    }

    private function restreindreArticle(QueryBuilder $queryBuilder, Utilisateur $utilisateur): void
    {
        $rootAlias = $queryBuilder->getRootAliases()[0];

        $queryBuilder
            ->andWhere(sprintf(
                "(%s.portee = 'global' OR EXISTS (SELECT 1 FROM %s aff_perimetre_support WHERE IDENTITY(aff_perimetre_support.etablissement) = IDENTITY(%s.etablissement) AND IDENTITY(aff_perimetre_support.utilisateur) = :perimetre_support_utilisateur))",
                $rootAlias,
                Affectation::class,
                $rootAlias,
            ))
            ->setParameter('perimetre_support_utilisateur', $utilisateur->getId(), 'uuid');
    }

    private function restreindreTicket(QueryBuilder $queryBuilder, Utilisateur $utilisateur): void
    {
        $rootAlias = $queryBuilder->getRootAliases()[0];
        $codes = $this->calculateur->codesEffectifs($utilisateur, $this->contexte->idActif());

        if (
            $this->calculateur->autorise($codes, 'support', 'administrer')
            || $this->calculateur->autorise($codes, 'support', 'traiter_ticket_n1')
            || $this->calculateur->autorise($codes, 'support', 'traiter_ticket_n2')
        ) {
            $queryBuilder
                ->innerJoin(
                    Affectation::class,
                    'aff_perimetre_ticket',
                    Join::WITH,
                    sprintf(
                        'IDENTITY(aff_perimetre_ticket.etablissement) = IDENTITY(%s.etablissement) AND IDENTITY(aff_perimetre_ticket.utilisateur) = :perimetre_ticket_utilisateur',
                        $rootAlias,
                    ),
                )
                ->setParameter('perimetre_ticket_utilisateur', $utilisateur->getId(), 'uuid')
                ->distinct();

            return;
        }

        if ($this->calculateur->autorise($codes, 'support', 'lire_ticket_etablissement')) {
            $etablissementActif = $this->contexte->idActif();
            if ($etablissementActif !== null) {
                $queryBuilder->andWhere($rootAlias . '.etablissement = :perimetre_ticket_etablissement')
                    ->setParameter('perimetre_ticket_etablissement', $etablissementActif, 'uuid');

                return;
            }
        }

        $queryBuilder->andWhere($rootAlias . '.demandeur = :perimetre_ticket_demandeur')
            ->setParameter('perimetre_ticket_demandeur', $utilisateur->getId(), 'uuid');
    }
}
