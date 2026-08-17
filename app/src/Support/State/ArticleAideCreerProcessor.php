<?php

declare(strict_types=1);

namespace App\Support\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Entity\Utilisateur;
use App\Support\Entity\ArticleAide;
use App\Support\Enum\OrigineArticle;
use App\Support\Enum\StatutArticle;
use App\Support\Service\ArticleAideEcritureService;
use App\Support\Service\ScopeArticleVerificateur;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * POST /support/articles (RG-SUP-02/04, CA-2/CA-3) : vérifie le scope (global/local, §3 plan) puis
 * délègue à `ArticleAideEcritureService::enregistrer()` (création de la `VersionArticle` n°1).
 *
 * @implements ProcessorInterface<ArticleAide, ArticleAide>
 */
final class ArticleAideCreerProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly ScopeArticleVerificateur $verificateur,
        private readonly ArticleAideEcritureService $ecriture,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ArticleAide
    {
        \assert($data instanceof ArticleAide);
        $utilisateur = $this->security->getUser();
        \assert($utilisateur instanceof Utilisateur);

        $this->verificateur->verifierOuRefuser($data);

        $data->setAuteur($utilisateur);
        $data->setOrigine(OrigineArticle::Manuel);
        $data->setStatut(StatutArticle::Brouillon);

        $this->ecriture->enregistrer($data, $utilisateur, OrigineArticle::Manuel);

        return $data;
    }
}
