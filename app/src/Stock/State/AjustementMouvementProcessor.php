<?php

declare(strict_types=1);

namespace App\Stock\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Entity\Utilisateur;
use App\Stock\Entity\ArticleStock;
use App\Stock\Entity\MouvementStock;
use App\Stock\Entity\ReceptionAchat;
use App\Stock\Enum\TypeMouvementStock;
use App\Stock\Service\AjustementStockHandler;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * `POST /stock/mouvements/ajustement` (§4 du plan, CA-6/CA-7). Corps :
 * { "articleStock": uuid, "type": "ajustement_positif"|"ajustement_negatif"|"perte_casse"|"retour_fournisseur",
 *   "quantite": string, "motif": string, "receptionOrigine"?: uuid }.
 *
 * @implements ProcessorInterface<mixed, MouvementStock>
 */
final class AjustementMouvementProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly AjustementStockHandler $handler,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MouvementStock
    {
        $corps = $this->lecteur->corps();

        $article = $this->resoudre(ArticleStock::class, $corps['articleStock'] ?? null);
        if (!$article instanceof ArticleStock) {
            throw new UnprocessableEntityHttpException('Champ « articleStock » obligatoire.');
        }

        $type = TypeMouvementStock::tryFrom(\is_string($corps['type'] ?? null) ? $corps['type'] : '');
        if ($type === null) {
            throw new UnprocessableEntityHttpException('Champ « type » invalide.');
        }

        $quantite = \is_scalar($corps['quantite'] ?? null) ? (string) $corps['quantite'] : '';
        $motif = \is_string($corps['motif'] ?? null) ? $corps['motif'] : '';

        $receptionOrigine = null;
        if (isset($corps['receptionOrigine'])) {
            $reception = $this->resoudre(ReceptionAchat::class, $corps['receptionOrigine']);
            $receptionOrigine = $reception instanceof ReceptionAchat ? $reception : null;
        }

        $utilisateur = $this->security->getUser();
        $auteur = $utilisateur instanceof Utilisateur ? $utilisateur : null;

        $mouvement = $this->handler->ajuster($article, $type, $quantite, $motif, $receptionOrigine, $auteur);
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
