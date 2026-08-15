<?php

declare(strict_types=1);

namespace App\Sport\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Sport\Entity\RepresentationSepa;
use App\Sport\Enum\ResultatRepresentationSepa;
use App\Sport\Service\MoteurAntiImpayesHandler;
use App\Vente\Service\LecteurCorps;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /sport/representations/{id}/enregistrer-resultat (US-SPORT-06, CA-6). Corps : { "resultat": "reussie"|"echouee" }.
 *
 * @implements ProcessorInterface<RepresentationSepa, RepresentationSepa>
 */
final class EnregistrerResultatRepresentationProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly MoteurAntiImpayesHandler $handler,
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
