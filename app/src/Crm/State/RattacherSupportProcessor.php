<?php

declare(strict_types=1);

namespace App\Crm\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Crm\Entity\Client;
use App\Vente\Port\RechercheSupportInterface;
use App\Vente\Service\LecteurCorps;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /clients/{id}/rattacher-support (CA-1, §2.3 plan-crm.md) : vérifie qu'un n° de support/carte
 * émis par M2 est bien rattaché à cette fiche client (recherche par n° de carte).
 * Corps : { "identifiant": "…" }.
 *
 * @implements ProcessorInterface<Client, JsonResponse>
 */
final class RattacherSupportProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly RechercheSupportInterface $rechercheSupport,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        \assert($data instanceof Client);

        $identifiant = \is_string($this->lecteur->corps()['identifiant'] ?? null) ? (string) $this->lecteur->corps()['identifiant'] : '';
        if ($identifiant === '') {
            throw new UnprocessableEntityHttpException('Le champ « identifiant » (n° de support) est requis.');
        }

        $clientTrouve = $this->rechercheSupport->clientPourSupport($identifiant);
        if ($clientTrouve === null) {
            throw new NotFoundHttpException('Aucun client rattaché à ce n° de support.');
        }
        if ((string) $clientTrouve !== (string) $data->getId()) {
            throw new UnprocessableEntityHttpException('Ce n° de support est rattaché à un autre client.');
        }

        return new JsonResponse(['client' => (string) $data->getId(), 'identifiant' => $identifiant, 'rattache' => true]);
    }
}
