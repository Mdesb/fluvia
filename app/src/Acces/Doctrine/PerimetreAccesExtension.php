<?php

declare(strict_types=1);

namespace App\Acces\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Acces\Entity\Appairage;
use App\Acces\Entity\Controleur;
use App\Acces\Entity\DeclarationPerteVol;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Equipement;
use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\JaugeFmi;
use App\Acces\Entity\JetonTerminal;
use App\Acces\Entity\JournalReconciliation;
use App\Acces\Entity\ListeRevocation;
use App\Acces\Entity\Passage;
use App\Acces\Entity\ProductAccessZone;
use App\Acces\Entity\SousReseau;
use App\Acces\Entity\Support;
use App\Acces\Entity\Terminal;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement multi-entités des ressources L3 (RG-SOCLE-05) : un utilisateur ne voit que les
 * objets d'accès rattachés à un établissement où il possède au moins une affectation. Étend le
 * mécanisme du socle aux entités App\Acces (même pattern que M2 §7, PerimetreVenteExtension).
 */
final class PerimetreAccesExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /** @var array<class-string, string> Chemin (relatif à l'alias racine) vers l'établissement. */
    private const CHEMINS = [
        EspaceAcces::class => '{root}.etablissement',

        // ⚠ AJOUTEE LE 31/08 -- ET ELLE NE RESSEMBLE PAS AUX AUTRES.
        //
        // `SousReseau` ne porte aucun rattachement : c'est un regroupement nomme d'espaces.
        // Ce qui fuyait n'etait donc pas SON contenu mais celui qu'elle DESIGNE -- la relation
        // `espaces` est serialisee en liste d'IRI, et une serialisation de relation ne passe
        // pas par le fournisseur d'item : le cloisonnement d'`EspaceAcces` ne s'y appliquait
        // pas. Un lecteur recevait 404 sur un espace etranger, et son identifiant ici.
        //
        // ⚠ ET CET IDENTIFIANT SUFFIT A AGIR : `POST /sport/espaces/{id}/sos` est
        // `PUBLIC_ACCESS` -- declenchement physique, sans authentification ni permission. La
        // seule protection etait que l'identifiant ne soit pas devinable.
        //
        // Le cloisonnement porte sur l'espace joint, pas sur la racine : un sous-reseau est
        // visible s'il contient un espace du site actif.
        SousReseau::class => 'sr_esp.etablissement',
        Controleur::class => '{root}.etablissement',
        Equipement::class => '{root}.etablissement',
        Support::class => '{root}.etablissement',
        Appairage::class => '{root}.etablissement',
        DroitAcces::class => '{root}.etablissement',
        // Posee avant l'ouverture de la ressource, pas apres : une entite exposee sans
        // cloisonnement ne produit pas d'erreur, elle produit des lignes en trop.
        ProductAccessZone::class => '{root}.establishment',
        Passage::class => '{root}.etablissement',
        DeclarationPerteVol::class => '{root}.etablissement',
        JaugeFmi::class => 'jfmi_esp.etablissement',
        ListeRevocation::class => 'jfmi_ctrl.etablissement',
        // plan-acces-terminal.md §3.3 : ajout additif — Terminal porte des opérations Get/GetCollection
        // administrateur (`/acces/terminaux*`) passant par l'extension standard. JetonTerminal/
        // JournalReconciliation n'ont pas d'opération API exposée dans ce lot (inerte ici, tracé pour
        // cohérence/complétude si un écran de lecture leur est ajouté ultérieurement).
        Terminal::class => '{root}.etablissement',
        JetonTerminal::class => '{root}.etablissement',
        JournalReconciliation::class => '{root}.etablissement',
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
        if (!isset(self::CHEMINS[$resourceClass])) {
            return;
        }
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];

        if ($resourceClass === JaugeFmi::class) {
            $queryBuilder->innerJoin($rootAlias . '.espace', 'jfmi_esp');
        } elseif ($resourceClass === ListeRevocation::class) {
            $queryBuilder->innerJoin($rootAlias . '.controleur', 'jfmi_ctrl');
        } elseif ($resourceClass === SousReseau::class) {
            // ⚠ SEULE JOINTURE ManyToMany DE CETTE EXTENSION, ET C'EST CE QUI LA REND SENSIBLE.
            //
            // Les autres joignent un ManyToOne : une ligne racine, une ligne jointe. Celle-ci
            // multiplie la racine par le nombre d'espaces du site actif -- un sous-reseau de trois
            // espaces sortirait trois fois, et `totalItems` mentirait a la pagination.
            //
            // La deduplication vient du `->distinct()` qui termine `restreindre()`, plus bas : il
            // s'applique a toutes les requetes restreintes, et il est le SEUL a compter ici. En
            // ajouter un second sur cette ligne ne ferait que suggerer, faussement, que la
            // deduplication est locale -- et enverrait chercher ailleurs le jour ou celui d'en bas
            // disparaitrait. `testUnSousReseauDePlusieursEspacesNApparaitQuUneFois` garde CELUI-LA.
            $queryBuilder->innerJoin($rootAlias . '.espaces', 'sr_esp');
        }

        $chemin = str_replace('{root}', $rootAlias, self::CHEMINS[$resourceClass]);

        // ── L'AXE EST L'ÉTABLISSEMENT ACTIF ──────────────────────────────────────────────────
        //
        // Bascule du 28/08. Le filtre portait sur le PÉRIMÈTRE du lecteur : un exploitant affecté à
        // trois sites voyait les données des trois, sous le titre d'un seul. Constaté dans le
        // navigateur — le tableau de bord d'un site créé le matin même annonçait une session de
        // caisse ouverte, celle du voisin, et la pastille « prêt à vendre » s'allumait sur un site
        // sans caisse.
        //
        // L'écran porte un sélecteur d'établissement et titre ses pages du site actif : les données
        // le suivent. Le périmètre dit ce qu'on a le DROIT de voir ; l'actif dit ce qu'on REGARDE.
        // `PermissionVoter` a déjà refusé un établissement hors périmètre avant cette requête : on
        // filtre, on ne rejuge pas.
        $actif = $this->contexte->idActif();
        if ($actif === null) {
            // Fermeture par défaut : une liste vide se remarque, une liste inter-établissements a
            // seulement l'air plus longue.
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $queryBuilder
            ->andWhere(sprintf('IDENTITY(%s) = :%s', $chemin, 'perimetre_acces_actif'))
            ->setParameter('perimetre_acces_actif', $actif, 'uuid')
            ->distinct();
    }
}
