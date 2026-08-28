<?php

declare(strict_types=1);

namespace App\Support\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use App\Securite\Service\ContexteEtablissement;
use App\Support\Entity\ArticleAide;
use App\Support\Entity\TicketSupport;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement multi-entités du back-office Support (RG-SOCLE-05, §4 plan-support.md), patron
 * `App\Personnel\Doctrine\PerimetrePersonnelExtension`.
 *
 * - `ArticleAide` (listing back-office, tous statuts) : visible si `portee=global`, **ou**
 *   `portee=local` et l'utilisateur possède une `Affectation` sur `etablissement` — appliqué **en
 *   plus** du filtrage droits/ciblage fait par le Voter/Provider public.
 * - `TicketSupport` : restreint selon la permission la plus large détenue —
 *   `administrer`/`traiter_ticket_n1`/`traiter_ticket_n2` → tous les tickets du périmètre
 *   d'affectation ; `lire_ticket_etablissement` → tickets de l'établissement actif uniquement ;
 *   sinon → `demandeur = utilisateur courant` (`lire_ticket_soi`).
 */
final class PerimetreSupportExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly ContexteEtablissement $contexte,
        private readonly CalculateurDroits $calculateur,
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

        if ($resourceClass === ArticleAide::class) {
            $this->restreindreArticle($queryBuilder, $utilisateur);

            return;
        }

        if ($resourceClass === TicketSupport::class) {
            $this->restreindreTicket($queryBuilder, $utilisateur);
        }
    }

    private function restreindreArticle(QueryBuilder $queryBuilder, Utilisateur $utilisateur): void
    {
        $rootAlias = $queryBuilder->getRootAliases()[0];

        $actif = $this->contexte->idActif();
        if ($actif === null) {
            // Pas de fermeture totale ici : un article global n'est d'aucun etablissement, donc
            // aucun etablissement actif ne lui manque. Fermer aurait vide la base de connaissance
            // pour tout le monde -- elle vient justement d'etre activee par defaut chez tous les
            // clients. Meme regle que `ResidualScopeExtension` : un rattachement absent signifie
            // « pas d'un etablissement », et non « d'un etablissement inconnu ».
            $queryBuilder->andWhere(sprintf("%s.portee = 'global'", $rootAlias));

            return;
        }

        $queryBuilder
            ->andWhere(sprintf(
                "(%s.portee = 'global' OR IDENTITY(%s.etablissement) = :perimetre_support_actif)",
                $rootAlias,
                $rootAlias,
            ))
            ->setParameter('perimetre_support_actif', $actif, 'uuid');
    }

    private function restreindreTicket(QueryBuilder $queryBuilder, Utilisateur $utilisateur): void
    {
        $rootAlias = $queryBuilder->getRootAliases()[0];
        $codes = $this->calculateur->codesEffectifs($utilisateur, $this->contexte->idActif());

        if (
            $this->calculateur->autorise($codes, 'support', 'administrer')
            || $this->calculateur->autorise($codes, 'support', 'traiter_ticket_n1')
            || $this->calculateur->autorise($codes, 'support', 'traiter_ticket_n2')
        ) {
                // ── L'AXE EST L'ÉTABLISSEMENT ACTIF ──────────────────────────────────────────────────
            //
            // Bascule du 28/08. Le filtre portait sur le PÉRIMÈTRE du lecteur : un exploitant affecté à
            // plusieurs sites voyait les données de tous, sous le titre d'un seul. Constaté à l'écran —
            // un site créé le matin même, sans caisse, annonçait une session de caisse ouverte, celle
            // du voisin, et sa pastille « prêt à vendre » s'allumait.
            //
            // L'écran porte un sélecteur d'établissement et titre ses pages du site actif : les données
            // le suivent. Le périmètre dit ce qu'on a le DROIT de voir ; l'actif dit ce qu'on REGARDE.
            //
            // Le droit reste vérifié ailleurs, et c'est ce qui rend la bascule sûre :
            // `ContexteEtablissement::idActif()` ne fait que lire l'en-tête — c'est un sélecteur, pas
            // une preuve — mais `CalculateurDroits::codesEffectifs()` ne retient que les affectations
            // portant SUR cet établissement, donc un en-tête hors périmètre ne donne aucun droit et le
            // voter refuse avant que cette requête n'existe. Éprouvé par `AxeEtablissementActifTest`.
            $actif = $this->contexte->idActif();
            if ($actif === null) {
                // Fermeture par défaut : une liste vide se remarque, une liste inter-établissements a
                // seulement l'air plus longue.
                $queryBuilder->andWhere('1 = 0');

                return;
            }

            $queryBuilder
                ->andWhere(sprintf('IDENTITY(%s.etablissement) = :perimetre_ticket_actif', $rootAlias))
                ->setParameter('perimetre_ticket_actif', $actif, 'uuid')
                ->distinct();

            return;
        }

        if ($this->calculateur->autorise($codes, 'support', 'lire_ticket_etablissement')) {
            $etablissementActif = $this->contexte->idActif();
            if ($etablissementActif !== null) {
                $queryBuilder->andWhere($rootAlias . '.etablissement = :perimetre_ticket_etablissement')
                    ->setParameter('perimetre_ticket_etablissement', $etablissementActif, 'uuid');

                return;
            }
        }

        $queryBuilder->andWhere($rootAlias . '.demandeur = :perimetre_ticket_demandeur')
            ->setParameter('perimetre_ticket_demandeur', $utilisateur->getId(), 'uuid');
    }
}
