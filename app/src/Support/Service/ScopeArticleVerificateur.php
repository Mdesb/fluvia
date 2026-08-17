<?php

declare(strict_types=1);

namespace App\Support\Service;

use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use App\Securite\Service\ContexteEtablissement;
use App\Support\Entity\ArticleAide;
use App\Support\Enum\PorteeArticle;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Garde de scope commune Post/Patch `ArticleAide` + pièces jointes (§3 plan) : `gerer_kb_globale`
 * pour un article global, `gerer_kb_locale` **et** établissement actif = établissement de
 * l'article pour un article local (RG-SUP-04).
 */
final class ScopeArticleVerificateur
{
    public function __construct(
        private readonly Security $security,
        private readonly ContexteEtablissement $contexte,
        private readonly CalculateurDroits $calculateur,
    ) {
    }

    public function verifierOuRefuser(ArticleAide $article): void
    {
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            throw new AccessDeniedException('Authentification requise.');
        }

        $codes = $this->calculateur->codesEffectifs($utilisateur, $this->contexte->idActif());

        if ($article->getPortee() === PorteeArticle::Global) {
            if (!$this->calculateur->autorise($codes, 'support', 'gerer_kb_globale')) {
                throw new AccessDeniedException('Permission support.gerer_kb_globale requise pour un article global.');
            }

            return;
        }

        if (!$this->calculateur->autorise($codes, 'support', 'gerer_kb_locale')) {
            throw new AccessDeniedException('Permission support.gerer_kb_locale requise pour un article local.');
        }

        $etablissementArticle = $article->getEtablissement();
        $etablissementActif = $this->contexte->idActif();
        if ($etablissementArticle === null || $etablissementActif === null || (string) $etablissementArticle->getId() !== (string) $etablissementActif) {
            throw new AccessDeniedException("L'établissement de l'article local doit correspondre à l'établissement actif (en-tête X-Etablissement).");
        }
    }
}
