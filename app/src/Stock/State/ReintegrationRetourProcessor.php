<?php

declare(strict_types=1);

namespace App\Stock\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Entity\Utilisateur;
use App\Stock\Entity\ArticleStock;
use App\Stock\Entity\MouvementStock;
use App\Stock\Service\ReintegrationRetourHandler;
use App\Vente\Entity\Avoir;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * `POST /stock/mouvements/reintegration-retour` (§0 décision n°5, CA-11). Corps :
 * { "avoirId": uuid, "articleStock": uuid, "quantite": string }. Invocation volontaire uniquement,
 * jamais déclenchée automatiquement à la création de l'`Avoir` (M2 non modifié).
 *
 * @implements ProcessorInterface<mixed, MouvementStock>
 */
final class ReintegrationRetourProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly ReintegrationRetourHandler $handler,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MouvementStock
    {
        $corps = $this->lecteur->corps();

        $avoir = $this->resoudre(Avoir::class, $corps['avoirId'] ?? null);
        if (!$avoir instanceof Avoir) {
            throw new UnprocessableEntityHttpException('Champ « avoirId » obligatoire (avoir introuvable).');
        }
        $article = $this->resoudre(ArticleStock::class, $corps['articleStock'] ?? null);
        if (!$article instanceof ArticleStock) {
            throw new UnprocessableEntityHttpException('Champ « articleStock » obligatoire.');
        }
        $quantite = \is_scalar($corps['quantite'] ?? null) ? (string) $corps['quantite'] : '';
        if ($quantite === '') {
            throw new UnprocessableEntityHttpException('Champ « quantite » obligatoire.');
        }

        $utilisateur = $this->security->getUser();
        $auteur = $utilisateur instanceof Utilisateur ? $utilisateur : null;

        $mouvement = $this->handler->reintegrer($article, $quantite, $avoir, $auteur);
        $this->em->flush();

        return $mouvement;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $classe
     *
     * @return T|null
     */
    private function resoudre(string $classe, mixed $reference): ?object
    {
        if (!\is_string($reference) || $reference === '') {
            return null;
        }
        $segment = str_contains($reference, '/') ? basename($reference) : $reference;
        if (!Uuid::isValid($segment)) {
            return null;
        }

        return $this->em->getRepository($classe)->find($segment);
    }
}
