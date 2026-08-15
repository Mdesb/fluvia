<?php

declare(strict_types=1);

namespace App\Crm\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Crm\Entity\Client;
use App\Crm\Service\FusionHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * GET /crm/fusions/previsualiser?sources[]=uuid&maitre=uuid (US-L5-08, CA-13) : champs divergents
 * entre fiches sources et maître, avant toute mutation.
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class PrevisualiserFusionProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requestStack,
        private readonly FusionHandler $handler,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $request = $this->requestStack->getCurrentRequest();
        $maitreId = (string) $request?->query->get('maitre', '');
        $sourceIds = (array) $request?->query->all('sources');

        $maitre = Uuid::isValid($maitreId) ? $this->em->getRepository(Client::class)->find(Uuid::fromString($maitreId)) : null;
        if (!$maitre instanceof Client) {
            throw new UnprocessableEntityHttpException('Paramètre « maitre » requis et valide.');
        }

        $sources = [];
        foreach ($sourceIds as $id) {
            if (\is_string($id) && Uuid::isValid($id)) {
                $client = $this->em->getRepository(Client::class)->find(Uuid::fromString($id));
                if ($client instanceof Client) {
                    $sources[] = $client;
                }
            }
        }
        if ($sources === []) {
            throw new UnprocessableEntityHttpException('Paramètre « sources[] » requis (≥ 1 fiche).');
        }

        return new JsonResponse([
            'maitre' => (string) $maitre->getId(),
            'sources' => array_map(static fn (Client $c): string => (string) $c->getId(), $sources),
            'champsDivergents' => $this->handler->previsualiserClients($sources, $maitre),
        ]);
    }
}
