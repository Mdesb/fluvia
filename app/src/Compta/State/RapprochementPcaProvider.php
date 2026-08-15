<?php

declare(strict_types=1);

namespace App\Compta\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Compta\Entity\EtalementPca;
use App\Compta\Entity\MouvementPca;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * GET /compta/pca/{id}/rapprochement (US-L4-05) : `resteAServirCentimes` vs somme des `MouvementPca`
 * liés — rapprochable à tout instant.
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class RapprochementPcaProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $id = $uriVariables['id'] ?? null;
        $uuid = match (true) {
            $id instanceof Uuid => $id,
            \is_string($id) && Uuid::isValid($id) => Uuid::fromString($id),
            default => null,
        };
        if ($uuid === null) {
            throw new NotFoundHttpException('Étalement PCA introuvable.');
        }

        $etalement = $this->em->getRepository(EtalementPca::class)->find($uuid);
        if ($etalement === null) {
            throw new NotFoundHttpException('Étalement PCA introuvable.');
        }

        /** @var list<MouvementPca> $mouvements */
        $mouvements = $this->em->getRepository(MouvementPca::class)->findBy(['etalement' => $etalement->getId()]);

        $dotations = 0;
        $reprises = 0;
        foreach ($mouvements as $mouvement) {
            if ($mouvement->getType() === \App\Compta\Enum\TypeMouvementPca::Dotation) {
                $dotations += $mouvement->getMontantCentimes();
            } else {
                $reprises += $mouvement->getMontantCentimes();
            }
        }

        return new JsonResponse([
            'etalement' => (string) $etalement->getId(),
            'montantReporteCentimes' => $etalement->getMontantReporteCentimes(),
            'resteAServirCentimes' => $etalement->getResteAServirCentimes(),
            'dotationsCentimes' => $dotations,
            'reprisesCentimes' => $reprises,
            'coherent' => $dotations - $reprises === $etalement->getResteAServirCentimes(),
        ]);
    }
}
