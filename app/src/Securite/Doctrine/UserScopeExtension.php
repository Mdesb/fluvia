<?php

declare(strict_types=1);

namespace App\Securite\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use App\Securite\Port\SupportAccessScopeInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Un utilisateur n'est visible que de qui partage un etablissement avec lui.
 *
 * ⚠ CE QUI FUYAIT, ET CE QUE LA COLLECTION REND.
 *
 * `GET /api/utilisateurs` est garde par `securite.gerer` — porte par le role CLIENT
 * << Administrateur groupe >> — et AUCUN fournisseur ni extension ne nommait `Utilisateur`. La
 * collection rendait donc les 44 comptes de la base, tous clients confondus, avec pour chacun
 * `email`, `nom`, `statut` et `dernierAcces`.
 *
 * Ce sont des donnees personnelles, et accessoirement la liste nominative de qui travaille chez un
 * concurrent avec ses heures de connexion.
 *
 * Mesure du 31/08 sur base jetable : l'administrateur du groupe A trouvait
 * `agent.groupeb@itcotation.com` dans sa liste. Voir `CloisonnementUtilisateursTest`.
 *
 * ── LES TROIS BRANCHES, ET AUCUNE N'EST DECORATIVE ──────────────────────────────────────────────
 *
 * 1. PARTAGE D'ETABLISSEMENT. Le cas normal : je vois qui travaille la ou je travaille.
 *
 * 2. ACCES D'ASSISTANCE. `PerimetreEtablissementExtension` porte deja cette branche et explique
 *    pourquoi elle n'affaiblit pas la regle : les acces sont nominatifs, motives, bornes dans le
 *    temps, et traces dans le journal d'audit DU CLIENT. ⚠ L'OMETTRE NE FERMERAIT RIEN, ELLE
 *    DEPLACERAIT LE CONTOURNEMENT : un agent qui depanne un client sans voir ses comptes n'a plus
 *    qu'une voie praticable, poser une affectation permanente — invisible, indistinguable d'une
 *    affectation normale, que personne ne pense a retirer. C'est exactement ce que RG-ED-07
 *    interdit, obtenu par la porte de service.
 *
 * 3. ⚠ LES COMPTES SANS AUCUNE AFFECTATION RESTENT VISIBLES, ET C'EST UNE LIMITE ASSUMEE.
 *
 *    L'ecran des comptes cree l'utilisateur PUIS l'affectation, et l'affectation est facultative
 *    (`if (payload.roleId && payload.etabId)`). Sans cette branche, un compte cree sans role
 *    disparaitrait de la liste a l'instant meme ou on vient de le creer — et l'administrateur le
 *    recreerait, en concluant que l'enregistrement a echoue.
 *
 *    Ce que cette branche laisse voir n'est pas une fuite inter-client au sens propre : un compte
 *    sans affectation n'appartient au perimetre de PERSONNE. Elle reste neanmoins une porte
 *    entrouverte, et la refermer proprement demanderait que la creation pose l'affectation dans le
 *    meme geste — un changement d'ecran et de contrat, pas une extension. Ecrit ici pour que ce
 *    soit une decision et non une decouverte.
 *
 * ⚠ Comparaisons en `= :cle` avec le type `'uuid'` : les identifiants sont des `BINARY(16)`, et un
 * `Uuid` compare sans type explicite part en chaine RFC 4122, ne correspond a rien, et ne leve pas.
 * La liste serait alors vide sans erreur — ce qui, sur un cloisonnement, ressemble exactement a
 * << il n'y a rien a voir >>. Forme imposee par le garde-fou D58.
 */
final class UserScopeExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly SupportAccessScopeInterface $assistance,
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
        if ($resourceClass !== Utilisateur::class) {
            return;
        }

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return;
        }

        $racine = $queryBuilder->getRootAliases()[0];

        // Branche 1 — un etablissement en commun. Les deux affectations sont posees a plat dans le
        // FROM plutot qu'en EXISTS imbrique : la condition de jointure est la meme, et une
        // imbrication de sous-requetes se relit mal pour un gain nul.
        $partage = sprintf(
            'SELECT 1 FROM %s aff_cible, %s aff_moi WHERE IDENTITY(aff_cible.utilisateur) = %s.id '
            . 'AND IDENTITY(aff_moi.utilisateur) = :perimetre_u_moi '
            . 'AND IDENTITY(aff_moi.etablissement) = IDENTITY(aff_cible.etablissement)',
            Affectation::class,
            Affectation::class,
            $racine,
        );

        // Branche 3 — aucun rattachement du tout. Voir l'en-tete : limite assumee, pas un oubli.
        $orphelin = sprintf(
            'SELECT 1 FROM %s aff_orph WHERE IDENTITY(aff_orph.utilisateur) = %s.id',
            Affectation::class,
            $racine,
        );

        $termes = [
            (string) $queryBuilder->expr()->exists($partage),
            (string) $queryBuilder->expr()->not($queryBuilder->expr()->exists($orphelin)),
        ];

        // Branche 2 — acces d'assistance. L'instant est fourni ici, le meme pour toute la requete :
        // c'est ce qui permet de tester le port a un moment choisi.
        foreach ($this->assistance->reachableEstablishmentIds($utilisateur, new \DateTimeImmutable()) as $rang => $identifiant) {
            $cle = 'perimetre_u_assistance_' . $rang;
            $termes[] = (string) $queryBuilder->expr()->exists(sprintf(
                'SELECT 1 FROM %s aff_ass_%d WHERE IDENTITY(aff_ass_%d.utilisateur) = %s.id '
                . 'AND IDENTITY(aff_ass_%d.etablissement) = :%s',
                Affectation::class,
                $rang,
                $rang,
                $racine,
                $rang,
                $cle,
            ));
            $queryBuilder->setParameter($cle, $identifiant, 'uuid');
        }

        $queryBuilder
            ->andWhere((string) $queryBuilder->expr()->orX(...$termes))
            ->setParameter('perimetre_u_moi', $utilisateur->getId(), 'uuid');
    }
}
