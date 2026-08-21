<?php

declare(strict_types=1);

namespace App\Finance\Treasury\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Finance\Treasury\Entity\BankAccount;
use App\Finance\Treasury\Service\PerimetreEtablissementsResolver;
use App\Finance\Treasury\Service\TreasuryPositionCalculator;
use App\Stock\Security\PerimetreEtablissementVerificateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * GET `/finance/treasury/position?asOf=&bankAccount=` (§0.8 du plan, RG-TRE-05, CA-4, non persisté) —
 * endpoint HTTP fin : parse les query params, revérifie D8 si `bankAccount` est fourni, délègue le
 * calcul à `TreasuryPositionCalculator` (réutilisé tel quel par `CashflowForecastCalculator`).
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class TreasuryPositionProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requestStack,
        private readonly PerimetreEtablissementsResolver $perimetreResolver,
        private readonly PerimetreEtablissementVerificateur $perimetre,
        private readonly TreasuryPositionCalculator $calculator,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $request = $this->requestStack->getCurrentRequest();
        $asOfBrut = $request?->query->get('asOf');
        $asOfDate = \is_string($asOfBrut) && $asOfBrut !== '' ? new \DateTimeImmutable($asOfBrut) : new \DateTimeImmutable('today');

        $compteUnique = null;
        $bankAccountId = $request?->query->get('bankAccount');
        if (\is_string($bankAccountId) && $bankAccountId !== '') {
            $idNettoye = basename($bankAccountId);
            if (!Uuid::isValid($idNettoye)) {
                return new JsonResponse(['asOfDate' => $asOfDate->format('Y-m-d'), 'balance' => '0.00', 'perAccount' => []]);
            }
            $compteUnique = $this->em->find(BankAccount::class, Uuid::fromString($idNettoye));
            // D8 revérifié : un compte hors périmètre ne renvoie jamais de solde (échec fermé).
            $this->perimetre->verifier($compteUnique?->getEstablishment(), 'Compte bancaire introuvable.');
        }

        $resultat = $this->calculator->position($this->perimetreResolver->etablissementsAutorises(), $asOfDate, $compteUnique);

        return new JsonResponse([
            'asOfDate' => $resultat['asOfDate'],
            'balance' => $this->decimal($resultat['balanceCents']),
            'perAccount' => array_map(
                static fn (array $c) => ['bankAccountId' => $c['bankAccountId'], 'label' => $c['label'], 'balance' => number_format($c['balanceCents'] / 100, 2, '.', '')],
                $resultat['perAccount'],
            ),
        ]);
    }

    private function decimal(int $centimes): string
    {
        return number_format($centimes / 100, 2, '.', '');
    }
}
