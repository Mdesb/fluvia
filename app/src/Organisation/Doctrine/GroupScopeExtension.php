<?php

declare(strict_types=1);

namespace App\Organisation\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Organisation\Entity\Groupe;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Un utilisateur ne voit que le groupe dont il depend.
 *
 * ⚠ CE QUE CETTE COLLECTION EST, ET POURQUOI SON OUVERTURE COMPTAIT.
 *
 * Un `Groupe` est un CLIENT : une regie, un delegataire, un groupe prive. `GET /api/groupes`
 * n'exigeait que `IS_AUTHENTICATED_FULLY`, et aucune extension de perimetre ne nommait cette
 * entite — elle etait la seule ressource structurante dans ce cas. La collection rendait donc la
 * liste des clients de l'editeur a n'importe quel utilisateur connecte, y compris un caissier. Ces
 * clients se trouvent parfois en concurrence sur les memes appels d'offres.
 *
 * Mesure du 31/08, sur base jetable : un utilisateur rattache a un groupe voyait dans la collection
 * l'IRI d'un groupe fabrique pour un autre client. Voir
 * `CloisonnementEcritureGroupeTest::testUnUtilisateurNeVoitPasLesGroupesDesAutresClients`.
 *
 * ── ⚠ POURQUOI UN CLOISONNEMENT ET NON UNE PERMISSION ───────────────────────────────────────────
 *
 * Durcir le `security:` vers un droit d'editeur aurait ferme la ressource a l'exploitant, alors
 * qu'il a des raisons legitimes de connaitre LE SIEN. La question n'est pas « qui a le droit de
 * lire des groupes » mais « lesquels » — c'est-a-dire un perimetre, comme partout ailleurs dans ce
 * depot.
 *
 * ── ⚠ CE QUI A ETE MESURE AVANT DE RESSERRER, ET CE QUI NE L'A PAS ETE ──────────────────────────
 *
 * Mesure : aucun ecran ne lit `/api/groupes` — grep sur tout `frontend/src`, avec
 * `/api/groupe_options` (une ressource sans rapport, 4 occurrences) comme temoin que le motif
 * trouve bien quelque chose. Les quatre lecteurs cote serveur — `PerimetreReportingResolver`,
 * `AgregateurMesuresService`, `RapportPlanifieProcessor`, `DashboardGroupeController` — passent par
 * le depot Doctrine et non par l'API : aucune extension ne s'y applique, ils continuent de
 * fonctionner.
 *
 * NON MESURE : un integrateur, un script ou un connecteur externe pourraient interroger cette
 * collection. Aucun grep du depot ne les voit. Si quelque chose d'invisible casse, c'est ici qu'il
 * faut revenir — et la reponse ne sera pas de rouvrir la collection, mais de donner a ce lecteur-la
 * un chemin nomme.
 *
 * ── LE CHEMIN DE RATTACHEMENT ───────────────────────────────────────────────────────────────────
 *
 * groupe <- region <- etablissement <- affectation -> utilisateur. Le meme que `Region::$groupe`
 * emprunte a l'ecriture, pour que ce qu'on peut ecrire et ce qu'on peut lire coincident.
 *
 * ⚠ L'ASSISTANCE N'EST PAS ETENDUE ICI, DELIBEREMENT. `PerimetreEtablissementExtension` ouvre une
 * seconde branche pour les acces d'assistance nominatifs. Rien ne la reclame ici : aucun ecran ne
 * lit cette collection, donc un agent d'assistance n'en a pas besoin pour travailler. L'ajouter
 * sans un besoin constate elargirait la seule ressource qui nomme les clients.
 *
 * ⚠ La comparaison passe par `= :cle` avec le type `'uuid'` : `org_*.id` est un `BINARY(16)`, et un
 * `Uuid` compare sans type explicite part en chaine RFC 4122, ne correspond a rien, et ne leve pas.
 * Une liste vide ressemble alors exactement a « il n'y a rien ». C'est la forme que le garde-fou
 * D58 impose, et la raison pour laquelle il l'impose.
 */
final class GroupScopeExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(private readonly Security $security)
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
        // ⚠ L'ITEM COMPTE AUTANT QUE LA COLLECTION, ET PAS SEULEMENT POUR LA LECTURE.
        //
        // API Platform resout l'IRI d'une relation en passant par le fournisseur d'ITEM. Une
        // extension qui ne cloisonnerait que la collection laisserait donc n'importe qui DESIGNER
        // un groupe etranger dans le corps d'une requete d'ecriture. C'est precisement ce qui
        // protege les entites comptables — leur profil est cloisonne a l'item — et precisement ce
        // qui manquait ici.
        $this->restreindre($queryBuilder, $resourceClass);
    }

    private function restreindre(QueryBuilder $queryBuilder, string $resourceClass): void
    {
        if ($resourceClass !== Groupe::class) {
            return;
        }

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];

        $rattachement = $queryBuilder->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(Affectation::class, 'aff_groupe')
            ->join('aff_groupe.etablissement', 'etab_groupe')
            ->join('etab_groupe.region', 'reg_groupe')
            ->where(sprintf('IDENTITY(reg_groupe.groupe) = %s.id', $rootAlias))
            ->andWhere('IDENTITY(aff_groupe.utilisateur) = :perimetre_groupe_utilisateur');

        $queryBuilder
            ->andWhere((string) $queryBuilder->expr()->exists($rattachement->getDQL()))
            ->setParameter('perimetre_groupe_utilisateur', $utilisateur->getId(), 'uuid');
    }
}
