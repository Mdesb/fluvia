<?php

declare(strict_types=1);

namespace App\Dms\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Dms\Entity\Document;
use App\Dms\Entity\DocumentPublicLink;
use App\Dms\Entity\DocumentVersion;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement multi-entités des ressources `App\Dms` (RG-DMS-01, plan-dms.md §3.2) — copie stricte du
 * patron `App\Ocr\Doctrine\PerimetreOcrExtension`/`App\Finance\*\Doctrine\PerimetreFinanceExtension`
 * (jointure `Affectation` sur `establishment` + utilisateur courant, `Security::getUser()`, jamais un id
 * transmis par le client). `RetentionPolicy` **volontairement absent** (catalogue global, non
 * cloisonné, arbitrage D18 pt.7). S'applique uniquement à `GetCollection`/`Get` — échec fermé (404,
 * jamais une liste vide indiscernable d'un « pas encore de documents », CA-1).
 */
final class DmsScopeExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /** @var array<class-string, list<string>> Relations à joindre depuis la racine jusqu'à « establishment ». */
    private const CHAINES = [
        Document::class => [],
        DocumentVersion::class => ['document'],
        DocumentPublicLink::class => ['document'],
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
        if (!isset(self::CHAINES[$resourceClass])) {
            return;
        }
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        foreach (self::CHAINES[$resourceClass] as $i => $relation) {
            $nouvelAlias = 'dms_scope_' . $i;
            $queryBuilder->innerJoin($alias . '.' . $relation, $nouvelAlias);
            $alias = $nouvelAlias;
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
            ->andWhere(sprintf('IDENTITY(%s.establishment) = :scope_dms_actif', $alias))
            ->setParameter('scope_dms_actif', $actif, 'uuid')
            ->distinct();
    }
}
