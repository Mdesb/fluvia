<?php

declare(strict_types=1);

namespace App\Stock\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Entity\Utilisateur;
use App\Stock\Entity\ArticleStock;
use App\Stock\Entity\TransfertStock;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * `POST /stock/transferts` (US-STOCK-10, RG-STOCK-14, CA-12). Corps :
 * { "articleStockSource": uuid, "articleStockDestination": uuid, "quantite": string }.
 *
 * @implements ProcessorInterface<mixed, TransfertStock>
 */
final class CreerTransfertProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TransfertStock
    {
        $corps = $this->lecteur->corps();

        $source = $this->resoudre($corps['articleStockSource'] ?? null);
        $destination = $this->resoudre($corps['articleStockDestination'] ?? null);
        if ($source === null || $destination === null) {
            throw new UnprocessableEntityHttpException('Champs « articleStockSource »/« articleStockDestination » obligatoires.');
        }
        if ((string) $source->getId() === (string) $destination->getId()) {
            throw new UnprocessableEntityHttpException('La source et la destination doivent être des articles distincts.');
        }
        if ($source->getEtablissement() !== null && $destination->getEtablissement() !== null
            && (string) $source->getEtablissement()->getId() === (string) $destination->getEtablissement()->getId()) {
            throw new UnprocessableEntityHttpException('La source et la destination doivent appartenir à des établissements différents (RG-STOCK-13).');
        }
        $quantite = \is_scalar($corps['quantite'] ?? null) ? (string) $corps['quantite'] : '';
        if ($quantite === '') {
            throw new UnprocessableEntityHttpException('Champ « quantite » obligatoire.');
        }

        $utilisateur = $this->security->getUser();

        $transfert = new TransfertStock();
        $transfert->setArticleStockSource($source)
            ->setArticleStockDestination($destination)
            ->setQuantite($quantite)
            ->setDemandePar($utilisateur instanceof Utilisateur ? $utilisateur : null);
        $this->em->persist($transfert);
        $this->em->flush();

        return $transfert;
    }

    private function resoudre(mixed $reference): ?ArticleStock
    {
        if (!\is_string($reference) || $reference === '') {
            return null;
        }
        $segment = str_contains($reference, '/') ? basename($reference) : $reference;
        if (!Uuid::isValid($segment)) {
            return null;
        }

        return $this->em->getRepository(ArticleStock::class)->find($segment);
    }
}
