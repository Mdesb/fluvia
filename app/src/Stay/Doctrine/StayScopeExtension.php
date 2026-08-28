<?php

declare(strict_types=1);

namespace App\Stay\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Stay\Entity\Stay;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement des ressources `App\Stay` (RG-SOCLE-05, D3) — même patron que
 * `App\Recouvrement\Doctrine\PerimetreRecouvrementExtension`.
 *
 * **Cette classe est le seul rempart des lectures en collection.** `StayScopeGuard` protège les
 * écritures et les résolutions explicites ; ici, c'est la requête elle-même qui est restreinte, avant
 * qu'API Platform ne materialise quoi que ce soit. Les deux sont nécessaires : un `GetCollection` ne
 * passe par aucun processor, et un séjour absent de la requête ne peut pas fuiter par un oubli plus haut.
 *
 * **`CHAINS` doit lister toute entité du module exposée en `ApiResource`.** En oublier une ne casse
 * rien de visible — la ressource répond, simplement sans filtre, c'est-à-dire en IDOR. C'est pourquoi
 * `StayScopeExtensionTest` compare cette table à ce qui est réellement exposé, par réflexion :
 * l'oubli devient un test rouge au lieu d'une faille silencieuse.
 *
 * @see \App\Stay\Security\StayScopeGuard pour le versant écriture
 */
final class StayScopeExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /** @var array<class-string, list<string>> Relations à joindre depuis la racine jusqu'à « establishment ». */
    public const CHAINS = [
        Stay::class => [],
    ];

    public function __construct(
        private readonly Security $security,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $this->restrict($queryBuilder, $resourceClass);
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
        $this->restrict($queryBuilder, $resourceClass);
    }

    private function restrict(QueryBuilder $queryBuilder, string $resourceClass): void
    {
        if (!isset(self::CHAINS[$resourceClass])) {
            return;
        }

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        foreach (self::CHAINS[$resourceClass] as $i => $relation) {
            $nextAlias = 'stay_scope_' . $i;
            $queryBuilder->innerJoin($alias . '.' . $relation, $nextAlias);
            $alias = $nextAlias;
        }

        // ── L'AXE EST L'ÉTABLISSEMENT ACTIF ──────────────────────────────────────────────────
        //
        // Bascule du 28/08. Le filtre portait sur le PÉRIMÈTRE du lecteur : un exploitant affecté à
        // plusieurs sites voyait les données de tous, sous le titre d'un seul. Constaté à l'écran —
        // un site créé le matin même, sans caisse, annonçait une session de caisse ouverte, celle
        // du voisin, et sa pastille « prêt à vendre » s'allumait.
        //
        // L'écran porte un sélecteur d'établissement et titre ses pages du site actif : les données
        // le suivent. Le périmètre dit ce qu'on a le DROIT de voir ; l'actif dit ce qu'on REGARDE.
        //
        // Le droit reste vérifié ailleurs, et c'est ce qui rend la bascule sûre :
        // `ContexteEtablissement::idActif()` ne fait que lire l'en-tête — c'est un sélecteur, pas
        // une preuve — mais `CalculateurDroits::codesEffectifs()` ne retient que les affectations
        // portant SUR cet établissement, donc un en-tête hors périmètre ne donne aucun droit et le
        // voter refuse avant que cette requête n'existe. Éprouvé par `AxeEtablissementActifTest`.
        $actif = $this->contexte->idActif();
        if ($actif === null) {
            // Fermeture par défaut : une liste vide se remarque, une liste inter-établissements a
            // seulement l'air plus longue.
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $queryBuilder
            ->andWhere(sprintf('IDENTITY(%s.establishment) = :scope_stay_actif', $alias))
            ->setParameter('scope_stay_actif', $actif, 'uuid')
            ->distinct();
    }
}
