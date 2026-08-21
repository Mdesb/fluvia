<?php

declare(strict_types=1);

namespace App\Finance\Treasury\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Finance\Treasury\Entity\BankStatementLine;
use App\Finance\Treasury\Service\BankReconciliationSuggestionCalculator;
use App\Stock\Security\PerimetreEtablissementVerificateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Uid\Uuid;

/**
 * GET `/finance/treasury/statement-lines/{id}/suggestions` (§0.7 du plan, non persisté) — retourne
 * `list<ReconciliationCandidate>`. Résolution + revérification explicite du périmètre (D8), même patron
 * que `ReconciliationGapProvider` (FIN-2).
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class ReconciliationSuggestionProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BankReconciliationSuggestionCalculator $calculator,
        private readonly PerimetreEtablissementVerificateur $perimetre,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $id = $uriVariables['id'] ?? null;
        if (!\is_string($id) || !Uuid::isValid($id)) {
            return new JsonResponse([]);
        }

        $ligne = $this->em->find(BankStatementLine::class, Uuid::fromString($id));
        $etablissement = $ligne?->getStatementImport()?->getBankAccount()?->getEstablishment();
        // Échec fermé (D8) : ne distingue jamais « n'existe pas » de « hors périmètre ».
        $this->perimetre->verifier($etablissement, 'Ligne de relevé introuvable.');
        \assert($ligne instanceof BankStatementLine);

        $candidats = $this->calculator->candidats($ligne);

        return new JsonResponse(array_map(static fn ($c) => $c->toArray(), $candidats));
    }
}
