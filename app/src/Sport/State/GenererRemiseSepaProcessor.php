<?php

declare(strict_types=1);

namespace App\Sport\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organisation\Entity\Etablissement;
use App\Securite\Service\ContexteEtablissement;
use App\Sport\Entity\RemiseSepa;
use App\Sport\Service\GenererRemiseSepaHandler;
use App\Vente\Service\LecteurCorps;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /sport/remises/generer (§2.1/§3 du plan). Corps optionnel : { "dateExecutionPrevue"?: "AAAA-MM-JJ" }.
 *
 * @implements ProcessorInterface<mixed, RemiseSepa>
 */
final class GenererRemiseSepaProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly GenererRemiseSepaHandler $handler,
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
        $date = isset($corps['dateExecutionPrevue']) && \is_string($corps['dateExecutionPrevue'])
            ? new \DateTimeImmutable($corps['dateExecutionPrevue'])
            : new \DateTimeImmutable('today');

        return $this->handler->generer($etablissement, $date);
    }
}
