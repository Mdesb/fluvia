<?php

declare(strict_types=1);

namespace App\Vente\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Vente\Entity\Vente;
use App\Vente\Service\PaymentAttemptStore;
use App\Vente\Service\SettlementCoordinator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `GET /ventes/{id}/reglement-terminal` : ce que l'écran scrute pendant l'attente du terminal (spec
 * TPE lot 1, CA-1, CA-5) — la tentative `?tentative=<id>`, ou celle qui tient la vente.
 *
 * ⚠ LA VENTE EST LUE PAR LE FOURNISSEUR DOCTRINE, PAS PAR UN `find()` : ses extensions appliquent le
 * périmètre de l'appelant. La vente d'un autre établissement est donc introuvable (404), et une
 * tentative n'est rendue que si elle porte sur CETTE vente (CA-8).
 *
 * La lecture juge le délai du connecteur : une tentative envoyée sans issue au-delà devient
 * `unresolved`, et l'écran passe à la déclaration. C'est la seule écriture d'un GET, et elle n'écrit
 * que ce que la prochaine demande aurait jugé de même — mais c'est une écriture : la route exige
 * `vente.encaisser`, le droit de qui scrute sa propre carte, pas celui de qui consulte (relecture S3).
 *
 * @implements ProviderInterface<object>
 */
final class TerminalAttemptProvider implements ProviderInterface
{
    /** @param ProviderInterface<object> $ventes */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.item_provider')]
        private readonly ProviderInterface $ventes,
        private readonly SettlementCoordinator $coordinateur,
        private readonly PaymentAttemptStore $attempts,
        private readonly RequestStack $requetes,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $vente = $this->ventes->provide($operation, $uriVariables, $context);
        if (!$vente instanceof Vente) {
            throw new NotFoundHttpException('Vente introuvable.');
        }

        $demandee = $this->requetes->getCurrentRequest()?->query->get('tentative');
        $tentative = $demandee !== null ? $this->coordinateur->attemptOnSale($vente, $demandee) : $this->attempts->holding($vente);
        if ($tentative === null || !$tentative['usesTerminal']) {
            throw new NotFoundHttpException('Aucun règlement au terminal de cet identifiant sur cette vente.');
        }
        $tentative = $this->coordinateur->judged($tentative);

        return new JsonResponse(PaymentAttemptStore::summary($tentative) + [
            'vente' => (string) $vente->getId(),
            'resteAPayer' => $vente->getResteAPayer(),
        ]);
    }
}
