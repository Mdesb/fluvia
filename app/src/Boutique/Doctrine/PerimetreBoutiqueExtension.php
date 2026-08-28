<?php

declare(strict_types=1);

namespace App\Boutique\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Boutique\Entity\AllocationQuotaOTA;
use App\Boutique\Entity\CompteClient;
use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Enum\StatutPanier;
use App\Boutique\Entity\DemandeRemboursement;
use App\Boutique\Entity\PartenaireOTA;
use App\Boutique\Entity\RetraitClickCollect;
use App\Boutique\Entity\ReversementOTA;
use App\Boutique\Entity\Vitrine;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement multi-entités des ressources back-office Boutique (RG-SOCLE-05, patron
 * `PerimetreReservationExtension`) : un agent staff ne voit que les objets rattachés à un
 * établissement où il possède au moins une affectation. Le tunnel public (panier, tunnel de
 * commande) n'est PAS filtré ici : établissement résolu depuis la `Vitrine`/le `Panier` eux-mêmes,
 * jamais depuis l'en-tête `X-Etablissement` d'un visiteur anonyme (§3 du plan).
 */
final class PerimetreBoutiqueExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /** @var array<class-string, string> */
    private const CHEMINS = [
        Vitrine::class => '{root}.etablissement',
        DemandeRemboursement::class => '{root}.etablissement',
        RetraitClickCollect::class => '{root}.etablissement',
        PartenaireOTA::class => '{root}.etablissement',
        AllocationQuotaOTA::class => '{root}.etablissement',
        ReversementOTA::class => '{root}.etablissement',
    ];

    /**
     * UN COMPTE CLIENT SE CLOISONNE PAR SES ACHATS, PAS PAR SON INSCRIPTION.
     *
     * **Ce que faisait l'ancienne regle, et pourquoi elle etait fausse dans les deux sens.**
     * `CompteClient` portait `{root}.etablissement` comme les autres — c'est-a-dire la boutique ou le
     * compte est NE. Un exploitant voyait donc :
     *
     * - **pas** un client venu d'ailleurs qui lui achete tous les mois ;
     * - **mais** un client inscrit chez lui qui n'a jamais rien pris.
     *
     * Aucune des deux erreurs ne se remarque : la liste a une taille plausible, et personne ne compte
     * les clients d'un CRM.
     *
     * > **Un compte global est une IDENTITE. Ce qui appartient a un exploitant, ce sont les
     * > TRANSACTIONS faites chez lui.**
     *
     * **Le lien retenu est la commande, pas le panier.** Un panier abandonne dit qu'on a hesite ;
     * il ne fait pas de quelqu'un un client. `StatutPanier::TransformeEnCommande` est le moment ou
     * l'achat existe.
     *
     * **`EXISTS` et non une jointure.** Une jointure sur les paniers multiplierait les lignes du
     * compte par ses commandes — un client fidele apparaitrait douze fois. `DISTINCT` le masquerait,
     * au prix d'un tri sur toute la table.
     */
    private const CHEMIN_PAR_ACHAT = CompteClient::class;

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

        if ($resourceClass === self::CHEMIN_PAR_ACHAT) {
            $this->restreindreParAchat($queryBuilder, $utilisateur);

            return;
        }

        if (!isset(self::CHEMINS[$resourceClass])) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];
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
            ->andWhere(sprintf('IDENTITY(%s) = :%s', $chemin, 'perimetre_boutique_actif'))
            ->setParameter('perimetre_boutique_actif', $actif, 'uuid')
            ->distinct();
    }

    /**
     * Le compte est visible s'il porte au moins une COMMANDE sur un etablissement ou l'agent est
     * affecte.
     *
     * L'affectation reste la frontiere d'autorisation : elle dit ce que l'agent a le droit de voir.
     * L'achat dit ce qui appartient a cet etablissement. Les deux se composent, et retirer l'une des
     * deux ouvrirait soit le CRM du voisin, soit un CRM vide.
     */
    private function restreindreParAchat(QueryBuilder $queryBuilder, Utilisateur $utilisateur): void
    {
        $rootAlias = $queryBuilder->getRootAliases()[0];

        $actif = $this->contexte->idActif();
        if ($actif === null) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $queryBuilder
            ->andWhere(sprintf(
                'EXISTS (
                    SELECT 1 FROM %s pan
                    WHERE IDENTITY(pan.compteClient) = %s.id
                      AND IDENTITY(pan.etablissement) = :perimetre_achat_actif
                      AND pan.statut = :perimetre_achat_statut
                )',
                PanierEnLigne::class,
                $rootAlias,
            ))
            ->setParameter('perimetre_achat_actif', $actif, 'uuid')
            ->setParameter('perimetre_achat_statut', StatutPanier::TransformeEnCommande->value);
    }
}
