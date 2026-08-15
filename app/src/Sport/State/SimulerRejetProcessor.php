<?php

declare(strict_types=1);

namespace App\Sport\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Sport\Entity\EcheanceSepa;
use App\Sport\Entity\IncidentPrelevement;
use App\Sport\Service\MoteurAntiImpayesHandler;
use App\Vente\Service\LecteurCorps;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /sport/echeances/{id}/simuler-rejet — enregistre un retour banque de rejet (US-SPORT-05, CA-5,
 * CA-7). En production, ce fait générateur proviendrait de `CollecteurSepaInterface::relerverRetours()`
 * (aucune remise bancaire réelle dans ce lot, Risque n°2) ; exposé ici comme point d'entrée
 * opérationnel/testable équivalent (acteur « Système », §3 spec). Corps :
 *   { "codeRetour": string, "libelleRetour"?: string, "dateRejet"?: "AAAA-MM-JJ" }.
 *
 * @implements ProcessorInterface<EcheanceSepa, IncidentPrelevement>
 */
final class SimulerRejetProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly MoteurAntiImpayesHandler $handler,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): IncidentPrelevement
    {
        \assert($data instanceof EcheanceSepa);

        $corps = $this->lecteur->corps();
        $code = \is_string($corps['codeRetour'] ?? null) ? $corps['codeRetour'] : '';
        if (trim($code) === '') {
            throw new UnprocessableEntityHttpException('« codeRetour » est requis (code retour SEPA, ex. AM04).');
        }
        $libelle = isset($corps['libelleRetour']) && \is_string($corps['libelleRetour']) ? $corps['libelleRetour'] : null;
        $dateRejet = isset($corps['dateRejet']) && \is_string($corps['dateRejet'])
            ? new \DateTimeImmutable($corps['dateRejet'])
            : new \DateTimeImmutable('today');

        return $this->handler->detecterRejet($data, $code, $libelle, $dateRejet);
    }
}
