<?php

declare(strict_types=1);

namespace App\Facturation\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Facturation\Entity\CommercialDocument;
use App\Facturation\Entity\Facture;
use App\Facturation\Entity\ParametreFacturationEtablissement;
use App\Facturation\Entity\SerieNumerotation;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement multi-entités du module Facturation (RG-SOCLE-05, `plan-facturation.md` §3) : un
 * utilisateur ne voit que les ressources rattachées à un établissement où il possède au moins une
 * affectation. Même patron que `App\Vente\Doctrine\PerimetreVenteExtension`.
 *
 *  - `Facture` et `CommercialDocument` (FAC-1 : devis, bon de commande, bon de livraison) portent
 *    directement `etablissement` ;
 *  - `ParametreFacturationEtablissement` et `SerieNumerotation` (correctif revue de cohérence, défaut
 *    3 — sans ce durcissement, `GET /parametres-facturation` et `/series-numerotation` renvoyaient
 *    toutes les lignes tous établissements, fuite SIRET/TVA/numéros inter-tenants) ne portent qu'un
 *    `profilExploitant` : le rattachement passe par `ProfilExploitant::etablissementPrincipal` **ou**
 *    l'un de ses `etablissementsRattaches` (`ProfilExploitant::couvre()`).
 *
 * ⚠ HYPOTHÈSE reprise de la spec (§3, `plan-facturation.md` §3) — restriction plus fine de l'agent de
 * caisse à ses propres ventes encaissées : **non implémentée** dans ce lot, le cloisonnement s'arrête
 * à l'établissement (comme RG-SOCLE-05).
 */
final class PerimetreFacturationExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /** Ressources cloisonnées directement via un champ `etablissement` sur la racine. */
    private const RESOURCES_ETABLISSEMENT_DIRECT = [Facture::class, CommercialDocument::class];

    /** Ressources cloisonnées via `{root}.profilExploitant` (principal ou rattachés). */
    private const RESOURCES_VIA_PROFIL = [ParametreFacturationEtablissement::class, SerieNumerotation::class];

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
        $viaProfil = \in_array($resourceClass, self::RESOURCES_VIA_PROFIL, true);
        if (!\in_array($resourceClass, self::RESOURCES_ETABLISSEMENT_DIRECT, true) && !$viaProfil) {
            return;
        }
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];

        if ($viaProfil) {
            // `ParametreFacturationEtablissement`/`SerieNumerotation` ne portent qu'un profil
            // exploitant : le rattachement à un établissement passe par
            // `ProfilExploitant::etablissementPrincipal` OU l'un de ses `etablissementsRattaches`.
            $queryBuilder
                ->innerJoin($rootAlias . '.profilExploitant', 'profil_perimetre_facturation')
                ->leftJoin('profil_perimetre_facturation.etablissementsRattaches', 'etab_rattache_perimetre_facturation');

            $condition = '(IDENTITY(aff_perimetre_facturation.etablissement) = IDENTITY(profil_perimetre_facturation.etablissementPrincipal)'
                . ' OR aff_perimetre_facturation.etablissement = etab_rattache_perimetre_facturation)'
                . ' AND IDENTITY(aff_perimetre_facturation.utilisateur) = :perimetre_facturation_utilisateur';
        } else {
            $condition = sprintf(
                'IDENTITY(aff_perimetre_facturation.etablissement) = IDENTITY(%s.etablissement) AND IDENTITY(aff_perimetre_facturation.utilisateur) = :perimetre_facturation_utilisateur',
                $rootAlias,
            );
        }

        $queryBuilder
            ->innerJoin(Affectation::class, 'aff_perimetre_facturation', Join::WITH, $condition)
            ->setParameter('perimetre_facturation_utilisateur', $utilisateur->getId(), 'uuid')
            ->distinct();
    }
}
