<?php

declare(strict_types=1);

namespace App\Compta\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Compta\Entity\BordereauVersement;
use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\DeclarationEReporting;
use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\EtalementPca;
use App\Compta\Entity\HiddenLegalVatRate;
use App\Compta\Entity\ExpenseAccountMapping;
use App\Compta\Entity\ExportComptable;
use App\Compta\Entity\Journal;
use App\Compta\Entity\LettrageEcriture;
use App\Compta\Entity\MappingComptable;
use App\Compta\Entity\MouvementPca;
use App\Compta\Entity\PeriodeComptable;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\QualificationEquipement;
use App\Compta\Entity\Rad;
use App\Compta\Entity\RegieRecettes;
use App\Compta\Entity\TauxTva;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement des ressources comptables (RG-SOCLE-05, D3/D8).
 *
 * **Pourquoi ce fichier existe.** Le module `Compta` était le seul des vingt-six modules exposant des
 * ressources API à n'avoir **aucune** extension de périmètre — le répertoire `Doctrine/` était absent,
 * pas vide. Conséquence mesurée avant correction : `GET /ecritures-comptables`, protégé par la seule
 * permission `compta.lire`, renvoyait le **grand livre de tous les établissements** — montants,
 * comptes, journaux, périodes.
 *
 * Cette exposition est d'une autre nature que les sept IDOR du projet : un IDOR exige de connaître un
 * identifiant, ici il suffisait d'appeler la route. Trouvée par claude-C en dépistant les 235 entités
 * exposées par l'API.
 *
 * **Le rattachement existait déjà**, seule l'extension qui l'emprunte manquait :
 * `EcritureComptable::getEtablissement()` résolvait de longue date par
 * `profilExploitant.etablissementPrincipal`. C'était un oubli, pas une impasse de conception.
 *
 * **Ce qui est couvert, et ce qui ne l'est pas.** Les quinze entités ci-dessous ont un chemin
 * **vérifié** vers l'établissement. Six autres entités exposées du module n'en ont pas d'évident —
 * `BordereauPayFiP`, `FactureB2G`, `LettrageEcriture`, `MoyenPaiement`, `QualificationEquipement`,
 * `VenteImpayeeRegie` — et ne sont **délibérément pas** traitées ici : certaines sont probablement
 * globales à dessein (un moyen de paiement, une qualification d'équipement), et claude-C a établi que
 * `VenteImpayeeRegie` l'est par conception. Inventer un chemin non vérifié produirait un cloisonnement
 * qui filtre à côté — pire qu'une absence de filtre, parce qu'il rassure.
 */
final class AccountingScopeExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /**
     * Propriété menant au `ProfilExploitant`, par classe de ressource. `ExpenseAccountMapping` porte le
     * nom anglais (D5) : le module est historique et francophone, ses ajouts suivent la règle en vigueur.
     *
     * @var array<class-string, string>
     */
    private const VIA_PROFIL = [
        CompteComptable::class => 'profilExploitant',
        DeclarationEReporting::class => 'profilExploitant',
        EcritureComptable::class => 'profilExploitant',
        EtalementPca::class => 'profilExploitant',
        // Le MASQUAGE est propre a un exploitant : ce que la piscine municipale ne veut pas
        // voir ne regarde pas le musee voisin. Le REFERENTIEL, lui, n'est pas ici — un taux
        // legal est le meme pour tout le monde, et le cloisonner reviendrait a en donner une
        // copie par etablissement, donc a recreer la proliferation qu'il corrige.
        HiddenLegalVatRate::class => 'profilExploitant',
        ExpenseAccountMapping::class => 'businessProfile',
        ExportComptable::class => 'profilExploitant',
        Journal::class => 'profilExploitant',
        MappingComptable::class => 'profilExploitant',
        PeriodeComptable::class => 'profilExploitant',
        Rad::class => 'profilExploitant',
        RegieRecettes::class => 'profilExploitant',
        TauxTva::class => 'profilExploitant',
    ];

    /**
     * Rattachement indirect : une jointure intermédiaire mène au porteur du `ProfilExploitant`.
     *
     * @var array<class-string, string>
     */
    /**
     * ⚠ TROISIEME CHEMIN : PAR L'ESPACE, QUI PORTE L'ETABLISSEMENT DIRECTEMENT.
     *
     * Les deux cartes ci-dessus atteignent l'etablissement par le profil exploitant.
     * `QualificationEquipement` n'a pas de profil : elle qualifie un `Espace` du socle, et c'est
     * l'espace qui porte l'etablissement. Elle etait donc hors de portee de la structure — pas
     * oubliee d'une liste, mais sans chemin pour y entrer.
     *
     * Ce que la fuite exposait : pour chaque equipement de chaque client, son existence (via l'IRI
     * de l'espace) et sa qualification fiscale SPIC/SPA. Mesure du 31/08,
     * `CloisonnementQualificationTest`.
     */
    private const VIA_ESPACE = [
        QualificationEquipement::class => 'espace',
    ];

    private const VIA_RELATION = [
        BordereauVersement::class => 'regie',
        MouvementPca::class => 'ecritureLiee',

        // ⚠ DEUX SAUTS, ET C'EST POURQUOI ELLE MANQUAIT. `LettrageEcriture` porte une `ligne`, qui
        // porte une `ecriture`, qui porte le profil. La carte ne savait exprimer qu'un saut ; le
        // point separe desormais les segments, et chacun devient une jointure.
        //
        // Ce que la fuite exposait : quelles ecritures d'un exploitant ont ete rapprochees, quand et
        // par qui. Mesure du 31/08, `CloisonnementLettrageTest`.
        LettrageEcriture::class => 'ligne.ecriture',
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
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return;
        }

        $racine = $queryBuilder->getRootAliases()[0];

        if ($resourceClass === ProfilExploitant::class) {
            // Seule entité du module portant l'établissement directement.
            $chemin = $racine . '.etablissementPrincipal';
        } elseif (isset(self::VIA_PROFIL[$resourceClass])) {
            $queryBuilder->innerJoin($racine . '.' . self::VIA_PROFIL[$resourceClass], 'pe_scope');
            $chemin = 'pe_scope.etablissementPrincipal';
        } elseif (isset(self::VIA_RELATION[$resourceClass])) {
            // ⚠ LE CHEMIN PEUT COMPTER PLUSIEURS SAUTS. Un point separe les segments ; chacun
            // devient une jointure, la derniere portant le `profilExploitant`. Les entrees a un
            // seul saut passent par le meme code sans cas particulier.
            $precedent = $racine;
            foreach (explode('.', self::VIA_RELATION[$resourceClass]) as $rang => $segment) {
                $alias = 'rel_scope' . $rang;
                $queryBuilder->innerJoin($precedent . '.' . $segment, $alias);
                $precedent = $alias;
            }
            $queryBuilder->innerJoin($precedent . '.profilExploitant', 'pe_scope');
            $chemin = 'pe_scope.etablissementPrincipal';
        } elseif (isset(self::VIA_ESPACE[$resourceClass])) {
            // L'espace porte l'etablissement sans passer par un profil : un seul saut, et l'axe
            // reste le meme — l'etablissement ACTIF, comme partout ailleurs dans ce fichier.
            $queryBuilder->innerJoin($racine . '.' . self::VIA_ESPACE[$resourceClass], 'esp_scope');
            $chemin = 'esp_scope.etablissement';
        } else {
            return;
        }

        // Même patron que `PerimetreVenteExtension` : le filtre porte sur l'établissement ACTIF, et
        // non sur le périmètre d'affectation du lecteur. Le module atteint l'établissement à travers
        // le profil exploitant — chemin plus long, même axe.
        //
        // Le droit reste vérifié ailleurs : `idActif()` ne fait que lire l'en-tête, mais
        // `CalculateurDroits::codesEffectifs()` ne retient que les affectations portant sur cet
        // établissement, donc un en-tête hors périmètre ne donne aucun droit et le voter refuse en
        // amont. Éprouvé par `AxeEtablissementActifTest`.
        $actif = $this->contexte->idActif();
        if ($actif === null) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $queryBuilder
            ->andWhere(sprintf('IDENTITY(%s) = :accounting_scope_actif', $chemin))
            ->setParameter('accounting_scope_actif', $actif, 'uuid')
            ->distinct();
    }
}
