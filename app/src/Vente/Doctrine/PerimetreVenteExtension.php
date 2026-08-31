<?php

declare(strict_types=1);

namespace App\Vente\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Caisse\Entity\Caisse;
use App\Caisse\Entity\ClotureZ;
use App\Caisse\Entity\MouvementCaisse;
use App\Caisse\Entity\PointDeVente;
use App\Caisse\Entity\SessionCaisse;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Entity\Avoir;
use App\Vente\Entity\CardRejection;
use App\Vente\Nf525\Entity\OperationScellee;
use App\Vente\Entity\Vente;
use App\Vente\Nf525\Entity\DailyClosure;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement multi-entités des ressources M2 (RG-SOCLE-05) : un utilisateur ne voit que les
 * points de vente, sessions, ventes, avoirs, caisses et mouvements rattachés à un établissement où
 * il possède au moins une affectation. Étend le mécanisme du socle aux entités App\Vente/App\Caisse.
 */
final class PerimetreVenteExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /**
     * Chemin (relatif à l'alias racine) menant à l'établissement, par classe de ressource.
     *
     * @var array<class-string, string>
     */
    private const CHEMINS = [
        PointDeVente::class => '{root}.etablissement',
        SessionCaisse::class => '{root}.etablissement',
        Vente::class => '{root}.etablissement',
        Avoir::class => '{root}.etablissement',
        Caisse::class => 'pdv.etablissement',
        MouvementCaisse::class => 'sess.etablissement',
        ClotureZ::class => 'sess.etablissement',

        // Ajoutees le 28/08. Elles portaient un `etablissement` depuis toujours, et personne ne
        // s'en servait : une liste blanche ne protege que ce qu'on a pense a y ecrire, et son
        // oubli ne se voit pas -- la collection rend simplement des lignes de plus.
        //
        // Ce que la fuite exposait : les rejets de carte d'un autre etablissement, avec leur
        // identifiant client ; et ses clotures fiscales quotidiennes, c'est-a-dire son chiffre
        // d'affaires jour par jour.
        CardRejection::class => '{root}.etablissement',
        DailyClosure::class => '{root}.etablissement',

        // ⚠ AJOUTEE LE 31/08 : LE TROISIEME OUBLI DE CETTE MEME LISTE, ET LE PLUS GRAVE.
        //
        // Le commentaire ci-dessus a ete ecrit le 28/08 en ajoutant les deux precedentes. La lecon
        // etait juste, elle a ete appliquee a deux cas, et le troisieme est reste dehors trois jours
        // de plus. C'est le propre d'une liste blanche : elle ne protege que ce qu'on a pense a y
        // ecrire, et rien ne signale ce qu'on a oublie.
        //
        // Ce que la fuite exposait n'est pas un identifiant : `payloadCanonique` est LE CONTENU
        // CANONIQUE DE CHAQUE TRANSACTION, fige au scellement. Un agent d'un autre client, muni de
        // `caisse.lire`, lisait la collection entiere.
        //
        // Prouve par execution avant correctif : `testUneOperationScelleeNeSeLitPasDUnEtablissementALAutre`
        // recevait la collection complete, temoin compris. Signale par allaccess-b8.
        //
        // Elle passe par le point de vente, comme `Caisse` -- d'ou la jointure plus bas.
        OperationScellee::class => 'pdvScelle.etablissement',
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

        // Jointures intermédiaires éventuelles (caisse → pdv, mouvement/cloture → session).
        if ($resourceClass === Caisse::class) {
            $queryBuilder->innerJoin($rootAlias . '.pointDeVente', 'pdv');
        } elseif ($resourceClass === OperationScellee::class) {
            // Alias distinct de `pdv` : les deux jointures ne coexistent jamais dans la meme
            // requete, mais un alias partage est le genre de detail qui se met a compter le jour
            // ou quelqu'un ajoute une troisieme classe passant par le point de vente.
            $queryBuilder->innerJoin($rootAlias . '.pointDeVente', 'pdvScelle');
        } elseif ($resourceClass === MouvementCaisse::class || $resourceClass === ClotureZ::class) {
            $queryBuilder->innerJoin($rootAlias . '.session', 'sess');
        }

        $chemin = str_replace('{root}', $rootAlias, self::CHEMINS[$resourceClass]);

        // ── L'AXE EST L'ÉTABLISSEMENT ACTIF ──────────────────────────────────────────────────
        //
        // Corrigé le 28/08. Le filtre portait sur le PÉRIMÈTRE du lecteur : un exploitant affecté à
        // trois sites voyait les caisses des trois, sous le titre d'un seul. Le tableau de bord d'un
        // site créé le matin même annonçait une session ouverte — celle du voisin — et la pastille
        // « prêt à vendre » s'allumait sur un site sans caisse.
        //
        // L'écran porte un sélecteur et titre ses pages du site actif : les données le suivent.
        // Le périmètre dit ce qu'on a le DROIT de voir ; l'actif dit ce qu'on REGARDE.
        //
        // Le droit, lui, reste vérifié : `PermissionVoter` refuse déjà un établissement hors
        // périmètre avant que cette requête ne soit construite. On filtre, on ne rejuge pas.
        $actif = $this->contexte->idActif();
        if ($actif === null) {
            // Fermeture par défaut : sans établissement actif, rien. Une liste vide se remarque ;
            // une liste inter-établissements a seulement l'air plus longue.
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $queryBuilder
            ->andWhere(sprintf('IDENTITY(%s) = :perimetre_vente_actif', $chemin))
            ->setParameter('perimetre_vente_actif', $actif, 'uuid')
            ->distinct();
    }
}
