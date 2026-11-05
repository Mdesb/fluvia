<?php

declare(strict_types=1);

namespace App\Vente\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Vente\Entity\Vente;
use App\Vente\Service\LecteurCorps;
use App\Vente\Service\PaymentAttemptStore;
use App\Vente\Service\SettlementCoordinator;
use App\Vente\Tpe\BridgeOutcomeAuthenticator;
use App\Vente\Tpe\LocalBridgeTerminal;
use App\Vente\Tpe\TerminalOutcomeSink;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * `POST /ventes/{id}/issue-pont` : le navigateur de la caisse relaie l'issue que le pont local a
 * obtenue du terminal (#340). Corps : `{ tentative, resultat, signature }`, `resultat` et `signature`
 * tels que le pont les a rendus.
 *
 * L'issue n'est reçue que signée par le pont DE CE point de vente, pour CETTE tentative et CE montant
 * ({@see BridgeOutcomeAuthenticator}) ; elle passe ensuite par le chemin unique de l'issue
 * ({@see TerminalOutcomeSink}) : un refus ou une annulation n'écrivent aucun règlement, une issue relayée
 * deux fois n'en écrit qu'un, une acceptation après « Annuler » l'emporte encore.
 *
 * @implements ProcessorInterface<Vente, JsonResponse>
 */
final class BridgeOutcomeProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly SettlementCoordinator $coordinateur,
        private readonly BridgeOutcomeAuthenticator $authenticator,
        private readonly TerminalOutcomeSink $issues,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        if (!$data instanceof Vente) {
            throw new NotFoundHttpException('Vente introuvable.');
        }
        $corps = $this->lecteur->corps();
        $tentative = $this->coordinateur->attemptOnSale($data, $corps['tentative'] ?? null)
            ?? throw new NotFoundHttpException('Tentative de règlement introuvable sur cette vente.');
        if ($tentative['connector'] !== LocalBridgeTerminal::CONNECTOR) {
            throw new ConflictHttpException('Cette tentative n\'a pas été confiée au pont du terminal.');
        }
        $resultat = $corps['resultat'] ?? null;
        $signature = $corps['signature'] ?? null;
        if (!\is_string($resultat) || !\is_string($signature) || \strlen($resultat) > 4096) {
            throw new UnprocessableEntityHttpException('Corps attendu : { tentative, resultat, signature }, tels que le pont les a rendus.');
        }
        $pdv = $data->getPointDeVente() ?? throw new UnprocessableEntityHttpException('Aucun point de vente sur cette vente.');
        $uuid = PaymentAttemptStore::summary($tentative)['id'];

        $issue = $this->authenticator->verify($pdv, $resultat, $signature, $uuid, $tentative['amount']);
        $relue = $this->issues->record($tentative['id'], $issue) ?? $tentative;

        return new JsonResponse(['vente' => (string) $data->getId(), 'tentative' => PaymentAttemptStore::summary($relue)]);
    }
}
