<?php

declare(strict_types=1);

namespace App\Securite\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Audit\Entity\EntreeAudit;
use App\Crm\Entity\ParametrePmvEtablissement;
use App\Fonctionnalite\Entity\FonctionnaliteEtablissement;
use App\Legal\Entity\LegalDocument;
use App\Legal\Entity\LegalIdentity;
use App\Organisation\Entity\Espace;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Region;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * LE PÉRIMÈTRE DES MODULES QUI N'EN AVAIENT PAS.
 *
 * Cinq modules exposaient des collections sans aucune extension de périmètre : Audit,
 * Fonctionnalité, Legal, Organisation, et le paramétrage PMV du CRM — dont l'extension existe mais
 * cloisonne sur le **client**, pas sur l'établissement, et ne savait donc pas le porter.
 *
 * Toutes ces entités portaient déjà un établissement. C'est précisément ce qui les a cachées : le
 * garde-fou n°4 tenait « porte un établissement » pour « est cloisonné » jusqu'au 28/08.
 *
 * > **Porter un établissement, c'est pouvoir être cloisonné. Ce n'est pas l'être.**
 *
 * ── POURQUOI UN SEUL FICHIER PLUTÔT QUE CINQ ────────────────────────────────────────────────────
 *
 * Cinq extensions d'une entrée chacune diraient la même chose cinq fois, et la sixième manquerait
 * sans qu'on le voie. Ici la carte **est** la surface d'audit : on lit d'un coup d'œil tout ce qui
 * n'était pas cloisonné, et pourquoi. Quand un de ces modules se dotera de sa propre extension,
 * son entrée déménage — la carte rétrécit, ce qui est le bon sens de marche.
 *
 * ── TROIS AXES, PARCE QUE CES ENTITÉS NE SE RATTACHENT PAS PAREIL ───────────────────────────────
 *
 *   1. **relation** vers `Etablissement` — le cas courant ;
 *   2. **référence libre** (colonne `uuid` sans clé étrangère) — `EntreeAudit`, qui ne peut pas
 *      dépendre d'une entité qu'un effacement RGPD pourrait faire disparaître sous elle ;
 *   3. **groupe** — `Region`, qui n'appartient pas à un établissement mais en contient.
 *
 * ── CE QUE VAUT UN RATTACHEMENT NUL ─────────────────────────────────────────────────────────────
 *
 * Il **ne se cache pas**. C'était le premier choix — « le sens sûr de l'erreur est celui qui
 * restreint » — et la suite complète a montré ce qu'il coûtait : les entrées d'audit portant sur un
 * utilisateur ou un groupe n'ont pas d'établissement, parce qu'elles n'en concernent aucun. Les
 * masquer vidait le journal.
 *
 * `NULL` ne veut donc pas dire « à cacher », il veut dire **« ce n'est pas d'un établissement »**.
 * Ces lignes restent visibles à qui détient le droit ; les lignes RATTACHÉES, elles, sont
 * cloisonnées — c'est ce qui manquait, et c'est corrigé.
 *
 * ⚠ La contrepartie, dite ici pour qu'elle ne se découvre pas ailleurs : une ligne à laquelle on
 * aurait OUBLIÉ de poser son établissement se lit comme une ligne globale. Le rattachement est donc
 * une responsabilité de l'écriture, pas de la lecture.
 *
 * ── LA SOUS-REQUÊTE EST AUTONOME ────────────────────────────────────────────────────────────────
 *
 * `FilterEagerLoadingExtension` reconstruit la requête et perd silencieusement les jointures libres
 * qu'une extension ajoute. Un `EXISTS` autonome y survit ; sa disparition ne se verrait qu'aux
 * lignes en trop.
 */
final readonly class ResidualScopeExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /**
     * Relation vers `Etablissement` : classe → nom de la propriété.
     *
     * Deux orthographes cohabitent — `etablissement` sur les entités d'avant D5, `establishment`
     * sur celles d'après. Les confondre ne lève pas : Doctrine dirait « champ inconnu » à
     * l'exécution, sur une requête que personne ne rejoue.
     *
     * @var array<class-string, string>
     */
    private const RELATION = [
        ParametrePmvEtablissement::class => 'etablissement',
        FonctionnaliteEtablissement::class => 'etablissement',
        LegalDocument::class => 'establishment',
        LegalIdentity::class => 'establishment',
        Espace::class => 'etablissement',
    ];

    /**
     * Référence libre (colonne `uuid`, sans clé étrangère) : classe → nom de la propriété.
     *
     * @var array<class-string, string>
     */
    private const REFERENCE_LIBRE = [
        EntreeAudit::class => 'etablissement',
    ];

    /**
     * Rattachement par groupe : classe → nom de la propriété.
     *
     * @var array<class-string, string>
     */
    private const GROUPE = [
        Region::class => 'groupe',
    ];

    public function __construct(private Security $security)
    {
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
        // L'item aussi : sans cela, un identifiant deviné suffirait à lire la ligne.
        $this->restreindre($queryBuilder, $resourceClass);
    }

    private function restreindre(QueryBuilder $queryBuilder, string $resourceClass): void
    {
        $condition = $this->condition($queryBuilder, $resourceClass);
        if ($condition === null) {
            return;
        }

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $porteur = $alias . '.' . $this->propriete($resourceClass);

        $queryBuilder
            ->andWhere($queryBuilder->expr()->orX(
                // Non rattachée : elle n'est d'aucun établissement, elle n'est donc cachée à aucun.
                $queryBuilder->expr()->isNull($porteur),
                $queryBuilder->expr()->exists($condition),
            ))
            ->setParameter('perimetre_residuel_utilisateur', $utilisateur->getId(), 'uuid');
    }

    /** La propriété porteuse, quel que soit son axe — c'est elle qu'on teste pour la nullité. */
    private function propriete(string $resourceClass): string
    {
        return self::RELATION[$resourceClass]
            ?? self::REFERENCE_LIBRE[$resourceClass]
            ?? self::GROUPE[$resourceClass];
    }

    private function condition(QueryBuilder $queryBuilder, string $resourceClass): ?string
    {
        $alias = $queryBuilder->getRootAliases()[0];
        $base = 'SELECT aff_res.id FROM ' . Affectation::class . ' aff_res ';
        $utilisateur = 'IDENTITY(aff_res.utilisateur) = :perimetre_residuel_utilisateur';

        if (isset(self::RELATION[$resourceClass])) {
            return $base . 'WHERE ' . $utilisateur
                . ' AND IDENTITY(aff_res.etablissement) = IDENTITY('
                . $alias . '.' . self::RELATION[$resourceClass] . ')';
        }

        if (isset(self::REFERENCE_LIBRE[$resourceClass])) {
            // Pas de `IDENTITY()` ici : la propriété EST déjà l'identifiant, la colonne ne porte
            // aucune relation. L'envelopper ferait échouer la requête, pas la fuite.
            return $base . 'WHERE ' . $utilisateur
                . ' AND IDENTITY(aff_res.etablissement) = '
                . $alias . '.' . self::REFERENCE_LIBRE[$resourceClass];
        }

        if (isset(self::GROUPE[$resourceClass])) {
            return $base
                . 'INNER JOIN ' . Etablissement::class . ' etb_res WITH etb_res = aff_res.etablissement '
                . 'INNER JOIN ' . Region::class . ' reg_res WITH reg_res = etb_res.region '
                . 'WHERE ' . $utilisateur
                . ' AND IDENTITY(reg_res.groupe) = IDENTITY('
                . $alias . '.' . self::GROUPE[$resourceClass] . ')';
        }

        return null;
    }
}
