<?php

declare(strict_types=1);

namespace App\Recouvrement\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Recouvrement\Entity\RepresentationSepa;
use App\Recouvrement\Enum\ResultatRepresentationSepa;
use App\Recouvrement\Service\MoteurRecouvrementHandler;
use App\Vente\Service\LecteurCorps;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /recouvrement/representations/{id}/enregistrer-resultat. Corps : { "resultat": "reussie"|"echouee" }.
 *
 * @implements ProcessorInterface<RepresentationSepa, RepresentationSepa>
 */
final class EnregistrerResultatRepresentationProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly MoteurRecouvrementHandler $handler,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RepresentationSepa
    {
        \assert($data instanceof RepresentationSepa);

        $corps = $this->lecteur->corps();
        $resultat = ResultatRepresentationSepa::tryFrom(\is_string($corps['resultat'] ?? null) ? $corps['resultat'] : '');
        if ($resultat === null || $resultat === ResultatRepresentationSepa::EnAttente) {
            throw new UnprocessableEntityHttpException('« resultat » doit valoir « reussie » ou « echouee ».');
        }

        $this->handler->enregistrerResultatRepresentation($data, $resultat);

        return $data;
    }
}
