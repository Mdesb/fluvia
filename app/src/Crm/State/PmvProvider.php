<?php

declare(strict_types=1);

namespace App\Crm\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Crm\Entity\Client;
use App\Crm\Entity\PorteMonnaieVirtuel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * GET /clients/{id}/pmv (US-L5-04, CA-7/CA-10) : solde, échéance, statut. Si aucun PMV n'existe
 * encore (créé à la 1ʳᵉ recharge, §1.2 plan-crm.md), renvoie un solde nul/`expire` plutôt qu'un 404.
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class PmvProvider implements ProviderInterface
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

        return new JsonResponse([
            'client' => (string) $client->getId(),
            'solde' => $pmv?->getSolde() ?? '0.00',
            'devise' => $pmv?->getDevise() ?? 'EUR',
            'statut' => $pmv?->getStatut()->value ?? 'expire',
            'dateEcheance' => $pmv?->getDateEcheance()?->format('Y-m-d'),
        ]);
    }
}
