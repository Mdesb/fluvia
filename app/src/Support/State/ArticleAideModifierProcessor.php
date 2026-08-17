<?php

declare(strict_types=1);

namespace App\Support\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Entity\Utilisateur;
use App\Support\Entity\ArticleAide;
use App\Support\Enum\OrigineArticle;
use App\Support\Service\ArticleAideEcritureService;
use App\Support\Service\ScopeArticleVerificateur;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * PATCH /support/articles/{id} (RG-SUP-03, CA-5) : `portee`/`etablissement` ne sont pas dans le
 * groupe de dénormalisation `article:write:update` (immuables après création) — le scope est donc
 * vérifié sur les valeurs **inchangées** de l'article. Nouvelle `VersionArticle` systématique.
 *
 * @implements ProcessorInterface<ArticleAide, ArticleAide>
 */
final class ArticleAideModifierProcessor implements ProcessorInterface
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

        $this->ecriture->enregistrer($data, $utilisateur, OrigineArticle::Manuel);

        return $data;
    }
}
