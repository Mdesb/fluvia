<?php

declare(strict_types=1);

namespace App\Compta\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\Journal;
use App\Compta\Nf525\ScellementEcritureHandler;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * GET /compta/ecritures/verifier-chaine?journal=... (CA-13) : recalcule la chaîne NF525 d'un journal
 * et détecte les ruptures.
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class VerifierChaineEcritureProcessor implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ScellementEcritureHandler $scellement,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $journalId = $this->requestStack->getCurrentRequest()?->query->get('journal');
        if (!\is_string($journalId) || !Uuid::isValid($journalId)) {
            return new JsonResponse(['intacte' => true, 'nbOperations' => 0, 'anomalies' => []]);
        }

        $journal = $this->em->getRepository(Journal::class)->find(Uuid::fromString($journalId));
        if ($journal === null) {
            return new JsonResponse(['intacte' => true, 'nbOperations' => 0, 'anomalies' => []]);
        }

        $ecritures = $this->em->getRepository(EcritureComptable::class)->findBy(['journal' => $journal->getId()]);
        $rapport = $this->scellement->verifieChaine($ecritures);

        return new JsonResponse($rapport);
    }
}
