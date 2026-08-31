<?php

declare(strict_types=1);

namespace App\Securite\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use App\Securite\Port\SupportAccessScopeInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement multi-entités (RG-SOCLE-05, CA-4) : un utilisateur ne voit que les établissements
 * où il possède au moins une affectation — **ou un accès d'assistance ouvert**.
 *
 * ── LA SECONDE BRANCHE, ET POURQUOI ELLE N'AFFAIBLIT PAS LA RÈGLE ──────────────────────────────
 *
 * RG-ED-07 dit qu'il n'existe aucun rôle qui voit tous les établissements. Cette branche ne
 * l'entame pas : elle n'ouvre **rien par elle-même**. Le port rend un tableau vide dans tous les
 * cas douteux, et la liste qu'il rend est celle des accès **nominatifs, motivés et bornés dans le
 * temps** qu'un responsable a ouverts un par un — chacun tracé dans le journal d'audit de
 * l'établissement DU CLIENT, que le client lit.
 *
 * ⚠ SANS ELLE, LA RÈGLE SE CONTOURNE, ET LE CONTOURNEMENT DEVIENT LA PRATIQUE. Le jour où un client
 * appelle parce que sa caisse ne s'ouvre pas, l'agent avait tous les droits sur un établissement
 * qui n'apparaissait dans aucune liste : le sélecteur ne le proposait pas, et l'unique façon d'y
 * entrer était de connaître son identifiant et de forger l'en-tête à la main. La seule manière
 * praticable restait donc de **poser une affectation permanente** — invisible, indistinguable d'une
 * affectation normale, que personne ne penserait à retirer. C'est exactement ce que RG-ED-07
 * interdit, obtenu par la porte de service.
 *
 * ── ⚠ POURQUOI `EXISTS` A REMPLACÉ LA JOINTURE ────────────────────────────────────────────────
 *
 * La version d'origine posait un `innerJoin` sur `Affectation` : élégant tant qu'il n'y a qu'un
 * chemin, impossible dès qu'il y en a deux — une jointure interne ne sait pas dire « ou ». La
 * transformer en jointure externe rendrait des doublons et ferait dépendre le cloisonnement d'un
 * `DISTINCT` posé ailleurs. `EXISTS` dit la même chose et rend une ligne par établissement, quel
 * que soit le nombre d'affectations ; le `->distinct()` d'origine, qui n'était là que pour réparer
 * la multiplication de la jointure, disparaît avec elle.
 *
 * ── ⚠ LA COMPARAISON DES IDENTIFIANTS, ET LA FUITE SILENCIEUSE QU'ELLE ÉVITE ─────────────────
 *
 * `org_etablissement.id` est un `BINARY(16)`. Comparé sans type explicite, un `Uuid` part en chaîne
 * RFC 4122 sous l'inférence de Doctrine et ne correspond à **rien** — sans erreur, sans requête
 * invalide. La liste serait simplement celle d'avant, l'accès d'assistance n'ouvrirait rien, et le
 * défaut se lirait comme un cloisonnement qui fonctionne.
 *
 * D'où le type `'uuid'` passé en troisième argument de chaque `setParameter`, la seule forme que
 * Doctrine convertit correctement. La première version employait `IN (:liste)` avec une conversion
 * binaire manuelle : elle marchait, et le garde-fou D58 l'a refusée à raison — trois choses à tenir
 * ensemble, dont aucune ne se voit depuis la ligne qui compare, et la prochaine recopiée sans elles
 * rendrait une liste vide en silence.
 *
 * Le test d'intégration `SupportAccessPerimeterTest` existe pour attraper ce cas précis : le seul
 * témoin d'une conversion correcte est une ligne qui apparaît.
 */
final class PerimetreEtablissementExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
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
        if ($resourceClass !== Etablissement::class) {
            return;
        }

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];

        $affectations = $queryBuilder->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(Affectation::class, 'aff_perimetre')
            ->where(sprintf('IDENTITY(aff_perimetre.etablissement) = %s.id', $rootAlias))
            ->andWhere('IDENTITY(aff_perimetre.utilisateur) = :perimetre_utilisateur');

        $condition = (string) $queryBuilder->expr()->exists($affectations->getDQL());

        // ⚠ L'INSTANT EST FOURNI ICI, ET IL EST LE MÊME POUR TOUTE LA REQUÊTE. Le port ne lit pas
        // l'horloge : c'est ce qui permet de le tester à un moment choisi, et c'est aussi ce qui
        // garantit qu'une liste ne mélange pas deux instants si elle est longue à construire.
        $atteignables = $this->assistance->reachableEstablishmentIds($utilisateur, new \DateTimeImmutable());

        if ($atteignables !== []) {
            // ⚠ UNE ÉGALITÉ PAR ACCÈS, ET NON UN `IN (:liste)`.
            //
            // La version en `IN` fonctionnait — `ArrayParameterType::BINARY` plus une conversion
            // manuelle de chaque `Uuid`. Elle fonctionnait à condition de tenir TROIS choses
            // ensemble, dont aucune ne se voit depuis la ligne qui compare. Le garde-fou D58 refuse
            // cette forme, et ce qu'il protège n'est pas cette ligne-ci : c'est celle que
            // quelqu'un écrira à côté en la recopiant sans la conversion, et qui rendra une liste
            // vide **sans lever** — ce qui, en production, ressemble exactement à « il n'y a rien ».
            //
            // `= :clé` avec le type `'uuid'` en troisième argument est la forme que Doctrine
            // convertit tout seul, et celle que le dépôt a standardisée. Le coût est nul : un accès
            // d'assistance est exceptionnel par construction — nominatif, motivé, borné dans le
            // temps. Une poignée de termes `OR`, jamais une liste.
            $termes = [$condition];

            foreach ($atteignables as $rang => $identifiant) {
                $cle = 'perimetre_assistance_'.$rang;
                $termes[] = sprintf('%s.id = :%s', $rootAlias, $cle);
                $queryBuilder->setParameter($cle, $identifiant, 'uuid');
            }

            $condition = (string) $queryBuilder->expr()->orX(...$termes);
        }

        $queryBuilder
            ->andWhere($condition)
            ->setParameter('perimetre_utilisateur', $utilisateur->getId(), 'uuid');
    }
}
