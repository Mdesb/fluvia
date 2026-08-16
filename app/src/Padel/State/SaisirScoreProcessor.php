<?php

declare(strict_types=1);

namespace App\Padel\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Padel\Entity\InscriptionTournoi;
use App\Padel\Entity\MatchTournoi;
use App\Padel\Service\SaisirScoreHandler;
use App\Vente\Service\LecteurCorps;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * PATCH /padel/matchs/{id}/score (US-PADEL-06, CA-7). Corps : { "score": string, "vainqueur": "A"|"B" }.
 *
 * @implements ProcessorInterface<mixed, MatchTournoi>
 */
final class SaisirScoreProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly SaisirScoreHandler $handler,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MatchTournoi
    {
        \assert($data instanceof MatchTournoi);

        $corps = $this->lecteur->corps();
        $score = \is_string($corps['score'] ?? null) ? $corps['score'] : '';
        if ($score === '') {
            throw new UnprocessableEntityHttpException('Champ « score » obligatoire.');
        }

        $cote = $corps['vainqueur'] ?? null;
        $vainqueur = match ($cote) {
            'A' => $data->getPaireA(),
            'B' => $data->getPaireB(),
            default => null,
        };
        if (!$vainqueur instanceof InscriptionTournoi) {
            throw new UnprocessableEntityHttpException('Champ « vainqueur » obligatoire (« A » ou « B »).');
        }

        return $this->handler->saisir($data, $score, $vainqueur);
    }
}
