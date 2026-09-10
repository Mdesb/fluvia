<?php

declare(strict_types=1);

namespace App\Sport\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Sport\Entity\AlertePresenceIsolee;
use App\Sport\Entity\ConfigAccesNocturne;
use App\Membership\Entity\EcheanceSepa;
use App\Sport\Entity\EvenementSOS;
use App\Membership\Entity\MouvementComptableSepa;
use App\Membership\Entity\PauseAbonnement;
use App\Membership\Entity\Reengagement;
use App\Membership\Entity\Resiliation;
use App\Membership\Entity\StatutAccesFitness;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement multi-entités des ressources Sport (RG-SOCLE-05), même pattern que
 * `App\Piscine\Doctrine\PerimetrePiscineExtension`/`App\Acces\Doctrine\PerimetreAccesExtension`.
 * Le moteur anti-impayés (politique, incident, représentation, tableau de bord) a été extrait vers
 * `App\Recouvrement\Doctrine\PerimetreRecouvrementExtension` (refactor extraction) : plus référencé ici.
 */
final class PerimetreSportExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /** @var array<class-string, list<string>> Relations à joindre depuis la racine jusqu'à « etablissement ». */
    private const CHAINES = [
        MouvementComptableSepa::class => [],
        EcheanceSepa::class => ['abonnement'],
        StatutAccesFitness::class => ['abonnement'],
        PauseAbonnement::class => ['abonnement'],
        Resiliation::class => ['abonnement'],
        Reengagement::class => ['ancienAbonnement'],
        ConfigAccesNocturne::class => ['espaceAcces'],
        EvenementSOS::class => ['espaceAcces'],
        AlertePresenceIsolee::class => ['espaceAcces'],
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
            $nouvelAlias = 'sport_perimetre_' . $i;
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
            ->andWhere(sprintf('IDENTITY(%s.etablissement) = :perimetre_sport_actif', $alias))
            ->setParameter('perimetre_sport_actif', $actif, 'uuid')
            ->distinct();
    }
}
