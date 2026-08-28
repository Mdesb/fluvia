<?php

declare(strict_types=1);

namespace App\Marketing\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * LE PÉRIMÈTRE DES OBJETS MARKETING — ce qui manquait, et ce que personne n'aurait vu manquer.
 *
 * Les opérations d'item du module sont contrôlées une par une : `SendCampaignProcessor`,
 * `CampaignResultProvider` et `CampaignAttributionProvider` recalculent l'autorité contre
 * l'établissement de la campagne et répondent 404. Les **collections**, elles, ne l'étaient pas.
 *
 * `GET /api/segments` rendait donc les segments de tous les groupes à quiconque possède
 * `campagne.lire` quelque part, et `GET /api/campaigns` rendait **le texte intégral** des campagnes
 * des autres. Rien ne le signalait : une fuite de cloisonnement ne lève pas d'erreur.
 *
 * > **Une fuite de cloisonnement ne produit pas d'erreur, elle produit des lignes en trop.**
 *
 * ── POURQUOI L'EXTENSION PORTE SUR LE MODULE, ET NON SUR UNE LISTE DE CLASSES ───────────────────
 *
 * Une liste blanche de classes protège les entités qu'on a pensé à y écrire. Celle-ci s'applique à
 * **toute entité du module** : une entité ajoutée demain est cloisonnée sans que personne ait à s'en
 * souvenir. Et celle qu'on ne saurait pas rattacher fait **lever une exception** plutôt que de
 * passer : le sens sûr de l'erreur est celui qui restreint.
 *
 * ── LA SOUS-REQUÊTE EST AUTONOME, ET C'EST OBLIGATOIRE ──────────────────────────────────────────
 *
 * `FilterEagerLoadingExtension` reconstruit la requête et perd silencieusement les jointures libres
 * ajoutées par une extension. Un `EXISTS` autonome survit à cette reconstruction ; un `innerJoin`
 * n'y survit pas — et sa disparition ne se voit qu'aux lignes en trop.
 */
final readonly class MarketingScopeExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    private const NAMESPACE_MODULE = 'App\\Marketing\\Entity\\';

    public function __construct(
        private Security $security,
        private EntityManagerInterface $entityManager,
        private ContexteEtablissement $contexte,
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
        // L'item aussi : sans cela, l'identifiant d'un segment voisin suffirait à le lire.
        $this->restreindre($queryBuilder, $resourceClass);
    }

    private function restreindre(QueryBuilder $queryBuilder, string $resourceClass): void
    {
        if (!str_starts_with($resourceClass, self::NAMESPACE_MODULE)) {
            return;
        }

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            // Pas d'utilisateur, pas de périmètre : on ne rend rien. Rendre « tout » serait la
            // seule erreur irrattrapable de ce fichier.
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $porteur = $this->cheminVersLEtablissement($queryBuilder, $resourceClass, $alias);

        // ── L'AXE EST L'ÉTABLISSEMENT ACTIF, PAS LE PÉRIMÈTRE DU LECTEUR ─────────────────────
        //
        // Corrigé le 28/08, vu dans le navigateur : sur Patinoire B, l'onglet Fidélité affichait
        // le barème de Piscine A comme s'il était le sien. Ce n'était pas une fuite —
        // l'administratrice est affectée aux deux — c'était pire à sa manière : un chiffre juste
        // au mauvais endroit, que rien ne signale. Elle aurait posé un palier en croyant
        // configurer Patinoire B.
        //
        // Un barème, un palier, un segment, une campagne sont les réglages D'UN établissement.
        // Même axe que les référentiels cloisonnés du module Offre.
        $actif = $this->contexte->idActif();
        if ($actif === null) {
            // Fermeture par défaut : sans établissement actif, rien. Une liste vide se remarque ;
            // une liste inter-établissements a seulement l'air plus longue.
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        // Le droit d'être ici a déjà été vérifié par `PermissionVoter` contre cet établissement :
        // l'en-tête est un sélecteur, et le voter est la preuve. On filtre donc, on ne rejuge pas.
        $queryBuilder
            ->andWhere('IDENTITY(' . $porteur . '.establishment) = :marketing_etablissement_actif')
            ->setParameter('marketing_etablissement_actif', $actif, 'uuid');
    }

    /**
     * L'alias qui porte `establishment` — celui de l'entité, ou celui de son parent.
     *
     * Une entité du module qu'on ne saurait pas rattacher n'est pas laissée passer : elle lève. Un
     * cloisonnement absent se lit exactement comme un cloisonnement présent, alors qu'une exception
     * se voit au premier appel.
     */
    private function cheminVersLEtablissement(QueryBuilder $queryBuilder, string $resourceClass, string $alias): string
    {
        $metadonnees = $this->entityManager->getClassMetadata($resourceClass);

        if ($metadonnees->hasAssociation('establishment')) {
            return $alias;
        }

        if ($metadonnees->hasAssociation('campaign')) {
            $queryBuilder->innerJoin($alias . '.campaign', 'camp_mkt');

            return 'camp_mkt';
        }

        throw new \LogicException(sprintf(
            'L’entité %s appartient au module Campagnes mais ne se rattache à aucun établissement : '
            . 'elle ne peut pas être cloisonnée. Ajoutez-lui `establishment`, ou étendez %s.',
            $resourceClass,
            self::class,
        ));
    }
}
