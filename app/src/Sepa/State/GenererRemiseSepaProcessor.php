<?php

declare(strict_types=1);

namespace App\Sepa\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organisation\Entity\Etablissement;
use App\Sepa\Entity\RemiseSepa;
use App\Sepa\Service\CompositeEcheanceSepaSource;
use App\Sepa\Service\GenerationRemiseHandler;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Service\LecteurCorps;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /sepa/remises/generer (plan §6) : point d'entrée générique, agrège les échéances dues de toutes
 * les verticales branchées (`CompositeEcheanceSepaSource`). Corps optionnel :
 *   { "dateExecution"?: "AAAA-MM-JJ" }.
 *
 * @implements ProcessorInterface<mixed, RemiseSepa>
 */
final class GenererRemiseSepaProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly GenerationRemiseHandler $handler,
        private readonly CompositeEcheanceSepaSource $source,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RemiseSepa
    {
        $etablissement = $this->contexte->etablissementActif();
        if (!$etablissement instanceof Etablissement) {
            throw new UnprocessableEntityHttpException('Établissement actif requis (en-tête X-Etablissement).');
        }

        $corps = $this->lecteur->corps();
        $date = isset($corps['dateExecution']) && \is_string($corps['dateExecution'])
            ? new \DateTimeImmutable($corps['dateExecution'])
            : new \DateTimeImmutable('today');

        return $this->handler->generer($etablissement, $date, $this->source);
    }
}
