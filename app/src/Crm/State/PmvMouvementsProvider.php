<?php

declare(strict_types=1);

namespace App\Crm\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Crm\Entity\Client;
use App\Crm\Entity\MouvementPmv;
use App\Crm\Entity\PorteMonnaieVirtuel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * GET /clients/{id}/pmv/mouvements (US-L5-04) : relevé de mouvements, triés du plus récent au plus
 * ancien (§5 plan-crm.md).
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class PmvMouvementsProvider implements ProviderInterface
{
    use ResolutionClientSoiTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
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
        $client = $uuid === null ? null : $this->em->getRepository(Client::class)->find($uuid);
        if (!$client instanceof Client) {
            throw new NotFoundHttpException('Client introuvable.');
        }
        $this->verifierAccesSoi($client, 'crm.pmv_lire', 'crm.pmv_lire_soi');

        $pmv = $this->em->getRepository(PorteMonnaieVirtuel::class)->findOneBy(['client' => $client]);
        if ($pmv === null) {
            return new JsonResponse(['mouvements' => []]);
        }

        /** @var list<MouvementPmv> $mouvements */
        $mouvements = $this->em->getRepository(MouvementPmv::class)->findBy(['pmv' => $pmv], ['dateMouvement' => 'DESC']);

        return new JsonResponse([
            'mouvements' => array_map(static fn (MouvementPmv $m): array => [
                'id' => (string) $m->getId(),
                'type' => $m->getType()->value,
                'montant' => $m->getMontant(),
                'soldeApres' => $m->getSoldeApres(),
                'dateMouvement' => $m->getDateMouvement()->format(DATE_ATOM),
                'canal' => $m->getCanal()?->value,
                'refVenteM2' => $m->getRefVenteM2() === null ? null : (string) $m->getRefVenteM2(),
                'motif' => $m->getMotif(),
            ], $mouvements),
        ]);
    }
}
