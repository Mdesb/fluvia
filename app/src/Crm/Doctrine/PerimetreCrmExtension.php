<?php

declare(strict_types=1);

namespace App\Crm\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client;
use App\Crm\Entity\Consentement;
use App\Crm\Entity\CustomerContact;
use App\Crm\Entity\DemandeRGPD;
use App\Crm\Entity\Famille;
use App\Crm\Entity\JournalFusion;
use App\Crm\Entity\PorteMonnaieVirtuel;
use App\Crm\Entity\RegleConservation;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Region;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement multi-entités des ressources M4 par **Groupe** (spécificité de ce module, §6
 * plan-crm.md, ⚠ HYPOTHÈSE §10.1) : un utilisateur voit un client dès qu'il a **une seule**
 * affectation dans le groupe du client, quel que soit l'établissement précis — contrairement à
 * `PerimetreVenteExtension`/`PerimetreProduitExtension` qui filtrent par Établissement direct. Les
 * entités dépendantes (`PorteMonnaieVirtuel`, `Beneficiaire`, `Consentement`, `DemandeRGPD`,
 * `JournalFusion` via son survivant) sont filtrées par jointure vers `Client`/`Famille`.
 * `ParametrePmvEtablissement` suit, lui, le patron Établissement standard (non traité ici).
 */
final class PerimetreCrmExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /**
     * Association (relative à l'alias racine) à traverser pour atteindre l'entité porteuse de
     * `groupe` — `null` si la ressource porte `groupe` directement.
     *
     * @var array<class-string, string|null>
     */
    private const ASSOCIATION_VERS_GROUPE = [
        Client::class => null,
        Famille::class => null,
        RegleConservation::class => null,
        PorteMonnaieVirtuel::class => 'client',
        Beneficiaire::class => 'client',
        // Les contacts d'une societe suivent leur client, comme les beneficiaires.
        //
        // Sans cette ligne, leur collection etait lisible d'un etablissement a l'autre : un
        // exploitant aurait lu les interlocuteurs commerciaux d'un voisin -- nom, fonction,
        // courriel direct. C'est le garde-fou de couverture qui l'a vu, pas la relecture, et il
        // l'a vu parce qu'il cherche les entites exposees que RIEN ne peut filtrer.
        CustomerContact::class => 'customer',
        Consentement::class => 'client',
        DemandeRGPD::class => 'client',
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
        if ($resourceClass === JournalFusion::class) {
            // Portée sur la fiche/famille survivante : pas de FK dure (UUID logique), pas de
            // cloisonnement fin possible en DQL sans jointure applicative — laissé au contrôle
            // `crm.fusionner` (permission réservée Administrateur, cf. §6 plan-crm.md).
            return;
        }

        if (!\array_key_exists($resourceClass, self::ASSOCIATION_VERS_GROUPE)) {
            return;
        }

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];
        $association = self::ASSOCIATION_VERS_GROUPE[$resourceClass];

        // IDENTITY() n'accepte qu'un chemin d'association direct : la traversée root -> client reste
        // une jointure normale (gérée correctement par API Platform, y compris par
        // `FilterEagerLoadingExtension` qui réécrit la requête en sous-requête IN() pour les
        // collections). En revanche affectation -> établissement -> région -> groupe est exprimée en
        // sous-requête EXISTS() **auto-contenue** (alias locaux `aff_pcrm`/`etb_pcrm`/`reg_pcrm`) :
        // une jointure « libre » (sur une classe, pas une association) y est en effet **silencieusement
        // perdue** par `FilterEagerLoadingExtension::getQueryBuilderWithNewAliases()` lorsque son
        // `resourceClassResolver` optionnel n'est pas câblé (non fourni par le kernel applicatif ici) —
        // la sous-requête EXISTS, elle, est recopiée telle quelle (simple chaîne dans la clause WHERE).
        $aliasGroupe = $rootAlias;
        if ($association !== null) {
            $aliasGroupe = 'cible_perimetre_crm';
            $queryBuilder->innerJoin($rootAlias . '.' . $association, $aliasGroupe);
        }

        $sousRequete = 'SELECT aff_pcrm.id FROM ' . Affectation::class . ' aff_pcrm '
            . 'INNER JOIN ' . Etablissement::class . ' etb_pcrm WITH etb_pcrm = aff_pcrm.etablissement '
            . 'INNER JOIN ' . Region::class . ' reg_pcrm WITH reg_pcrm = etb_pcrm.region '
            . 'WHERE IDENTITY(aff_pcrm.utilisateur) = :perimetre_crm_utilisateur '
            . 'AND IDENTITY(reg_pcrm.groupe) = IDENTITY(' . $aliasGroupe . '.groupe)';

        $queryBuilder
            ->andWhere('EXISTS (' . $sousRequete . ')')
            ->setParameter('perimetre_crm_utilisateur', $utilisateur->getId(), 'uuid')
            ->distinct();
    }
}
