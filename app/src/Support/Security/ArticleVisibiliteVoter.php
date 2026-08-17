<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use App\Securite\Service\ContexteEtablissement;
use App\Support\Entity\ArticleAide;
use App\Support\Enum\PorteeArticle;
use App\Support\Service\EtablissementContexteResolver;
use App\Support\Service\VisibiliteArticleService;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * `ARTICLE_LIRE` (§4 plan-support.md) : un lecteur anonyme/agent voit selon `VisibiliteArticleService`
 * (publié + ciblage + portée/établissement) ; un rédacteur/admin voit tout dans son périmètre (global
 * entier, ou local pour son établissement actif), y compris brouillon/archivé.
 *
 * @extends Voter<string, ArticleAide>
 */
final class ArticleVisibiliteVoter extends Voter
{
    public const ATTRIBUTE = 'ARTICLE_LIRE';

    public function __construct(
        private readonly VisibiliteArticleService $visibilite,
        private readonly EtablissementContexteResolver $etablissementResolver,
        private readonly ContexteEtablissement $contexte,
        private readonly CalculateurDroits $calculateur,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::ATTRIBUTE && $subject instanceof ArticleAide;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        \assert($subject instanceof ArticleAide);

        $utilisateur = $token->getUser();
        if ($utilisateur instanceof Utilisateur) {
            $codes = $this->calculateur->codesEffectifs($utilisateur, $this->contexte->idActif());

            if ($this->calculateur->autorise($codes, 'support', 'administrer')) {
                return true;
            }
            if ($subject->getPortee() === PorteeArticle::Global && $this->calculateur->autorise($codes, 'support', 'gerer_kb_globale')) {
                return true;
            }
            if ($subject->getPortee() === PorteeArticle::Local && $this->calculateur->autorise($codes, 'support', 'gerer_kb_locale')) {
                $etablissementArticle = $subject->getEtablissement();
                $etablissementActif = $this->contexte->idActif();
                if ($etablissementArticle !== null && $etablissementActif !== null && (string) $etablissementArticle->getId() === (string) $etablissementActif) {
                    return true;
                }
            }
        }

        return $this->visibilite->estVisible($subject, $this->etablissementResolver->resoudre());
    }
}
