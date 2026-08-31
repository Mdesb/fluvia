<?php

declare(strict_types=1);

namespace App\Compta\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Compta\Entity\BordereauPayFiP;
use App\Compta\Entity\BordereauVersement;
use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\DeclarationEReporting;
use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\EtalementPca;
use App\Compta\Entity\FactureB2G;
use App\Compta\Entity\HiddenLegalVatRate;
use App\Compta\Entity\ExpenseAccountMapping;
use App\Compta\Entity\ExportComptable;
use App\Compta\Entity\Journal;
use App\Compta\Entity\LettrageEcriture;
use App\Compta\Entity\LigneEcriture;
use App\Compta\Entity\MappingComptable;
use App\Compta\Entity\MouvementPca;
use App\Compta\Entity\PeriodeComptable;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\Rad;
use App\Compta\Entity\RegieRecettes;
use App\Compta\Entity\TauxTva;
use App\Compta\Entity\VenteImpayeeRegie;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Facturation\Entity\Facture;
use App\Vente\Entity\Vente;
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
 * **Ce qui est couvert, et ce qui ne l'est pas.** Les quinze entités de `VIA_PROFIL`/`VIA_RELATION`
 * ont un chemin **vérifié** vers l'établissement par le profil exploitant.
 *
 * ── ⚠ TROIS ENTITÉS AJOUTÉES LE 31/08, ET LA RAISON QUI LES EXCLUAIT ÉTAIT FAUSSE ───────────────
 *
 * Cette note disait de six entités qu'elles n'avaient « pas de chemin évident » et que
 * `VenteImpayeeRegie` était globale par conception. Trois de ces six en ont un, mesuré :
 *
 *   `Vente::$etablissement`            relation DIRECTE — donc `venteOrigine`, pourtant un `Uuid`
 *                                      nu, mène à l'établissement par une sous-requête
 *   `LigneEcriture::$ecriture`         mène au `profilExploitant` que quinze entités empruntent déjà
 *
 * Ce n'était donc pas une impasse de conception mais une lecture incomplète, et le prix de l'erreur
 * n'est pas symétrique : la barrière restante était `compta.lire`, que **tous** les comptables de
 * **tous** les établissements portent. Un `BordereauPayFiP` porte la référence de transaction d'une
 * vente ; un `VenteImpayeeRegie` porte un `motif` en texte libre — donc ce qu'un régisseur écrit
 * vraiment : le nom d'un client, un chèque sans provision, une contestation. Ce n'est pas une fuite
 * d'identifiants, c'est une fuite de contenu entre clients d'un même SaaS.
 *
 * **Aucune fuite n'avait eu lieu** : les trois tables étaient vides. Ce qui a rendu la correction
 * urgente, c'est que les écrans qui les REMPLISSENT venaient d'être livrés (T28, T30) — le défaut
 * naissait avec la première ligne écrite, pas avant.
 *
 * **Puis la quatrième, le 01/09 — et le motif visait encore le mauvais champ.** La note disait de
 * `FactureB2G` qu'elle « porte un `clientRef`, autre chemin ». C'est exact et sans issue : ce champ
 * vient du destinataire, avec un `Uuid::v4()` **en repli** quand il n'en a pas — un identifiant qui
 * ne désigne rien. Mais le chemin n'était pas là : `Facture::$factureB2G` pointe **vers** le
 * bordereau, et `Facture` porte son établissement en direct. Une sous-requête à l'envers suffit.
 *
 * Deux fois de suite, la note a cherché un chemin *depuis* l'entité et conclu qu'il n'y en avait
 * pas. Chercher aussi ce qui pointe **vers** elle aurait donné la réponse dans les deux cas.
 *
 * Les deux dernières restent hors de cette extension et le motif tient pour elles : `MoyenPaiement`
 * et `QualificationEquipement` sont des référentiels — un moyen de paiement est le même pour tout le
 * monde, et le cloisonner reviendrait à en donner une copie par établissement.
 *
 * ── ⚠ SOUS-REQUÊTE AUTONOME, JAMAIS DE JOINTURE ────────────────────────────────────────────────
 *
 * `FilterEagerLoadingExtension` reconstruit la requête et **perd silencieusement** les jointures
 * libres ajoutées par une extension ; un `EXISTS` autonome y survit. Le piège est documenté par
 * `MarketingScopeExtension`, et sa disparition ne se verrait qu'aux lignes en trop — c'est-à-dire à
 * la fuite qu'on croyait avoir fermée.
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
    private const VIA_RELATION = [
        BordereauVersement::class => 'regie',
        MouvementPca::class => 'ecritureLiee',
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

    /**
     * Cloisonne les trois entités dont le rattachement ne passe pas par une relation joignable
     * depuis la racine. Rend `true` quand elle a traité la classe — auquel cas l'appelant s'arrête.
     *
     * ⚠ `EXISTS` autonome et non `innerJoin` : voir l'en-tête de classe. Une jointure libre est
     * perdue par `FilterEagerLoadingExtension` **sans erreur**, et le filtre disparu ne se voit
     * qu'aux lignes en trop.
     */
    private function restreindreParSousRequete(QueryBuilder $queryBuilder, string $resourceClass): bool
    {
        $racine = $queryBuilder->getRootAliases()[0];

        $sousRequete = match ($resourceClass) {
            // `venteOrigine` est un `Uuid` NU, pas une relation : rien à joindre, mais
            // `Vente::$etablissement` est direct, donc le chemin existe bel et bien.
            BordereauPayFiP::class, VenteImpayeeRegie::class => sprintf(
                'SELECT 1 FROM %s v_scope WHERE v_scope.id = %s.venteOrigine'
                .' AND IDENTITY(v_scope.etablissement) = :accounting_scope_actif',
                Vente::class,
                $racine,
            ),
            // ⚠ A L'ENVERS, ET C'EST LE SEUL SENS QUI EXISTE. `FactureB2G` ne porte aucune
            // relation vers la facture ; c'est `Facture::$factureB2G` qui pointe vers elle. Le
            // docblock cherchait un chemin depuis `clientRef` — champ qui vient du destinataire
            // avec un `Uuid::v4()` EN REPLI, donc un identifiant qui ne designe rien. Le chemin
            // etait de l'autre cote, et `Facture` porte son etablissement en direct.
            //
            // Aucun orphelin a craindre : `DepotChorusProHandler`, seul createur, rattache le
            // bordereau a sa facture dans le meme flush.
            FactureB2G::class => sprintf(
                'SELECT 1 FROM %s f_scope'
                .' WHERE IDENTITY(f_scope.factureB2G) = %s.id'
                .' AND IDENTITY(f_scope.etablissement) = :accounting_scope_actif',
                Facture::class,
                $racine,
            ),
            // Deux sauts : la ligne porte l'écriture, l'écriture porte le profil exploitant — le
            // même axe que les quinze autres entités, seulement plus long.
            LettrageEcriture::class => sprintf(
                'SELECT 1 FROM %s l_scope'
                .' JOIN l_scope.ecriture e_scope'
                .' JOIN e_scope.profilExploitant p_scope'
                .' WHERE l_scope.id = IDENTITY(%s.ligne)'
                .' AND IDENTITY(p_scope.etablissementPrincipal) = :accounting_scope_actif',
                LigneEcriture::class,
                $racine,
            ),
            default => null,
        };

        if ($sousRequete === null) {
            return false;
        }

        // Échec fermé, comme la suite de `restreindre` : sans établissement actif on ne rend rien,
        // jamais tout.
        $actif = $this->contexte->idActif();
        if ($actif === null) {
            $queryBuilder->andWhere('1 = 0');

            return true;
        }

        $queryBuilder
            ->andWhere(sprintf('EXISTS (%s)', $sousRequete))
            ->setParameter('accounting_scope_actif', $actif, 'uuid');

        return true;
    }

    private function restreindre(QueryBuilder $queryBuilder, string $resourceClass): void
    {
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return;
        }

        // Les trois entités sans relation directe au profil exploitant : traitées par sous-requête
        // autonome, en amont, parce que leur condition ne se réduit pas au `$chemin` unique
        // qu'applique la suite.
        if ($this->restreindreParSousRequete($queryBuilder, $resourceClass)) {
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
            $queryBuilder
                ->innerJoin($racine . '.' . self::VIA_RELATION[$resourceClass], 'rel_scope')
                ->innerJoin('rel_scope.profilExploitant', 'pe_scope');
            $chemin = 'pe_scope.etablissementPrincipal';
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
