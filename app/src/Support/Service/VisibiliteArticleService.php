<?php

declare(strict_types=1);

namespace App\Support\Service;

use App\Securite\Entity\Utilisateur;
use App\Support\Entity\ArticleAide;
use App\Support\Enum\PorteeArticle;
use App\Support\Enum\PublicCible;
use App\Support\Enum\StatutArticle;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

/**
 * Règles de visibilité publique communes (RG-SUP-04/06, §4.7 spec) : jamais brouillon/archivé,
 * jamais un article ciblé `agent` pour un lecteur non authentifié, jamais un article local hors de
 * l'établissement de contexte. Utilisée par `ArticleVisibiliteVoter` (item), `ArticlePublicProvider`
 * et `RechercheArticleAideProvider` (collections) — point de couplage unique (§2 plan).
 */
final class VisibiliteArticleService
{
    public function __construct(
        private readonly Security $security,
    ) {
    }

    private function estAgentAuthentifie(): bool
    {
        return $this->security->getUser() instanceof Utilisateur;
    }

    /** @return list<PublicCible> */
    private function publicsAutorises(): array
    {
        return $this->estAgentAuthentifie()
            ? [PublicCible::Agent, PublicCible::Usager, PublicCible::Tous]
            : [PublicCible::Usager, PublicCible::Tous];
    }

    public function estVisible(ArticleAide $article, ?Uuid $etablissementContexte): bool
    {
        if ($article->getStatut() !== StatutArticle::Publie) {
            return false;
        }

        $cible = $article->getPublicCible();
        if ($cible === null || !\in_array($cible, $this->publicsAutorises(), true)) {
            return false;
        }

        if ($article->getPortee() === PorteeArticle::Global) {
            return true;
        }

        $etablissementArticle = $article->getEtablissement();

        return $etablissementArticle !== null
            && $etablissementContexte !== null
            && (string) $etablissementArticle->getId() === (string) $etablissementContexte;
    }

    /** Applique les mêmes règles en filtres DQL sur un QueryBuilder dont l'alias racine est `ArticleAide`. */
    public function appliquerFiltres(QueryBuilder $qb, string $alias, ?Uuid $etablissementContexte): void
    {
        $qb->andWhere($alias . '.statut = :support_visib_statut')
            ->setParameter('support_visib_statut', StatutArticle::Publie->value);

        $publics = array_map(static fn (PublicCible $p) => $p->value, $this->publicsAutorises());
        $qb->andWhere($qb->expr()->in($alias . '.publicCible', ':support_visib_publics'))
            ->setParameter('support_visib_publics', $publics);

        if ($etablissementContexte !== null) {
            $qb->andWhere(
                '(' . $alias . '.portee = :support_visib_global OR (' . $alias . '.portee = :support_visib_local AND ' . $alias . '.etablissement = :support_visib_etab))'
            )
                ->setParameter('support_visib_global', PorteeArticle::Global->value)
                ->setParameter('support_visib_local', PorteeArticle::Local->value)
                ->setParameter('support_visib_etab', $etablissementContexte, 'uuid');
        } else {
            $qb->andWhere($alias . '.portee = :support_visib_global')
                ->setParameter('support_visib_global', PorteeArticle::Global->value);
        }
    }
}
