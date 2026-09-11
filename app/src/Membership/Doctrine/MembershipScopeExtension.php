<?php

declare(strict_types=1);

namespace App\Membership\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Membership\Entity\Membership;
use App\Membership\Entity\EcheanceSepa;
use App\Membership\Entity\MouvementComptableSepa;
use App\Membership\Entity\PauseAbonnement;
use App\Membership\Entity\Reengagement;
use App\Membership\Entity\Resiliation;
use App\Membership\Entity\StatutAccesFitness;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement des ressources `App\Membership` (RG-SOCLE-05), même patron que
 * `App\Sport\Doctrine\PerimetreSportExtension` et `App\Group\Doctrine\GroupScopeExtension`.
 *
 * ── ⚠ ÉCRITE AVANT D'AVOIR UN SEUL APPELANT, ET C'EST LE POINT ────────────────────────────────
 *
 * Au lot 0, `Membership` ne porte pas `#[ApiResource]` : cette extension ne s'exécutera donc
 * **jamais** tant que le lot 3 n'aura pas exposé l'entité. On pourrait la remettre à ce moment-là.
 *
 * C'est exactement ce qu'il ne faut pas faire. Les garde-fous n°5 (couverture de périmètre) et n°35
 * (entité rattachable hors liste) ne mordent que sur les entités EXPOSÉES : le jour où quelqu'un
 * ajoutera `#[ApiResource]` à `Membership`, ils crieront — mais entre-temps, si la moindre lecture
 * passe par un provider écrit à la main, elle sortira du filtre sans que rien ne le signale. Poser
 * le filet maintenant coûte trente lignes ; le poser après coup demande de se souvenir qu'il
 * manquait.
 *
 * `MembershipSocleTest` tient ce raisonnement en laisse : il vérifie que cette classe nomme bien
 * `Membership::class`, puisque aucun garde-fou ne le fait tant que l'entité n'est pas exposée.
 *
 * ── LE NOM DE LA CLASSE ────────────────────────────────────────────────────────────────────────
 *
 * `MembershipScopeExtension` et non `PerimetreMembershipExtension` : les deux conventions coexistent
 * dans le dépôt — `Perimetre*Extension` pour les modules historiques en français, `*ScopeExtension`
 * pour les modules à nommage anglais (`Group`, `Stay`, `Dining`, `Marketing`, `SmartFlow`…). Ce
 * module est du second groupe. Accessoirement, `Perimetre` ne passerait pas le garde-fou n°2.
 */
final class MembershipScopeExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /**
     * @var array<class-string, list<string>> Relations à joindre depuis la racine jusqu'à
     *                                        « etablissement ». Vide = l'entité le porte elle-même.
     */
    private const CHAINES = [
        Membership::class => [],
        // ── ⚠ RAPATRIÉES DE `PerimetreSportExtension` (lot 1b) ─────────────────────────────
        //
        // Ces six entités ont déménagé au lot 1 ; leur CLOISONNEMENT, lui, était resté déclaré
        // dans Sport. Ça fonctionnait — une extension peut nommer une classe d'ailleurs — et
        // c'est exactement ce qui rend l'oubli dangereux : Sport décidait du périmètre d'un
        // module qu'il ne possède plus, sans qu'aucun garde-fou ne le signale. Le jour où l'une
        // des deux règles change, personne ne sait laquelle s'applique.
        //
        // La chaîne dit le chemin à parcourir jusqu'à `etablissement` : vide quand l'entité le
        // porte elle-même, sinon la relation à traverser.
        MouvementComptableSepa::class => [],
        EcheanceSepa::class => ['abonnement'],
        StatutAccesFitness::class => ['abonnement'],
        PauseAbonnement::class => ['abonnement'],
        Resiliation::class => ['abonnement'],
        Reengagement::class => ['ancienAbonnement'],
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
            $nouvelAlias = 'membership_perimetre_' . $i;
            $queryBuilder->innerJoin($alias . '.' . $relation, $nouvelAlias);
            $alias = $nouvelAlias;
        }

        // ── L'AXE EST L'ÉTABLISSEMENT ACTIF, PAS LE PÉRIMÈTRE DU LECTEUR ────────────────────
        //
        // Reprise de la bascule du 28/08 faite sur les autres extensions. Filtrer sur le périmètre
        // ferait voir à un exploitant multi-sites les données de TOUS ses sites, sous le titre d'un
        // seul. Le périmètre dit ce qu'on a le DROIT de voir ; l'actif dit ce qu'on REGARDE.
        //
        // Ce qui rend la bascule sûre : `idActif()` ne fait que lire l'en-tête `X-Etablissement` —
        // c'est un sélecteur, pas une preuve — mais `CalculateurDroits::codesEffectifs()` ne retient
        // que les affectations portant SUR cet établissement. Un en-tête hors périmètre ne donne
        // donc aucun droit, et le voter refuse avant que cette requête n'existe.
        $actif = $this->contexte->idActif();
        if ($actif === null) {
            // ⚠ FERMETURE PAR DÉFAUT. Une liste vide se remarque ; une liste inter-établissements a
            // seulement l'air plus longue.
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $queryBuilder
            ->andWhere(sprintf('IDENTITY(%s.etablissement) = :perimetre_membership_actif', $alias))
            ->setParameter('perimetre_membership_actif', $actif, 'uuid')
            ->distinct();
    }
}
