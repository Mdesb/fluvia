<?php

declare(strict_types=1);

namespace App\Crm\Doctrine;

use App\Crm\Doctrine\CustomerScope;
use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client;
use App\Crm\Entity\Consentement;
use App\Crm\Entity\CommercialActivity;
use App\Crm\Entity\CustomerContact;
use App\Crm\Entity\Opportunity;
use App\Crm\Entity\DemandeRGPD;
use App\Crm\Entity\Famille;
use App\Crm\Entity\JournalFusion;
use App\Crm\Entity\PorteMonnaieVirtuel;
use App\Crm\Entity\RegleConservation;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Region;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
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

    /**
     * CE QUI SE CLOISONNE PAR ETABLISSEMENT, ET NON PAR GROUPE.
     *
     * Le fichier client suit l'enseigne : un client appartient au groupe, pas a l'un de ses sites
     * (RG-SOCLE-05). Une AFFAIRE et une ACTIVITE COMMERCIALE, elles, appartiennent a un site precis
     * -- c'est pour cela qu'elles portent `establishment`, estampille au serveur.
     *
     * Elles figuraient dans `ASSOCIATION_VERS_GROUPE` avec la valeur `null`, qui signifie dans cette
     * table « la ressource porte `groupe` elle-meme ». Elle ne le porte pas : le DQL visait un champ
     * inexistant et Doctrine refusait la requete, donc `/api/opportunities` repondait 500 en
     * collection comme en item. Les deux gestes de l'ecran Affaires -- « Qualifier » et « Perdue » --
     * echouaient a chaque clic, sous un tableau qui s'affichait parfaitement parce qu'il est servi
     * par un fournisseur dedie qui contourne cette extension.
     *
     * @var list<class-string>
     */
    private const PAR_ETABLISSEMENT = [
        Opportunity::class,
        CommercialActivity::class,
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
        if ($resourceClass === JournalFusion::class) {
            // Portée sur la fiche/famille survivante : pas de FK dure (UUID logique), pas de
            // cloisonnement fin possible en DQL sans jointure applicative — laissé au contrôle
            // `crm.fusionner` (permission réservée Administrateur, cf. §6 plan-crm.md).
            return;
        }

        if (\in_array($resourceClass, self::PAR_ETABLISSEMENT, true)) {
            $this->restreindreParEtablissement($queryBuilder);

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

        // La clause vit dans `CustomerScope`. Elle était écrite ici ET recopiée à la main dans
        // `RechercheClientProvider` ; le module Campagnes en aurait écrit une troisième. Une copie
        // d'une règle de cloisonnement n'est pas de la duplication de code : c'est une seconde
        // politique de sécurité que personne ne maintient — le jour où la règle change, il en reste
        // une version périmée, et c'est elle qui décide qui voit quoi.
        CustomerScope::restreindreAuGroupe($queryBuilder, $aliasGroupe, $utilisateur->getId());
    }

    private function restreindreParEtablissement(QueryBuilder $queryBuilder): void
    {
        $actif = $this->contexte->idActif();
        if ($actif === null) {
            // Fermeture par defaut : une liste vide se remarque, une liste inter-etablissements a
            // seulement l'air plus longue.
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];
        $queryBuilder
            ->andWhere(sprintf('IDENTITY(%s.establishment) = :perimetre_crm_actif', $rootAlias))
            ->setParameter('perimetre_crm_actif', $actif, 'uuid');
    }
}
