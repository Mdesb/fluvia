<?php

declare(strict_types=1);

namespace App\Finance\Treasury\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Finance\Treasury\Entity\BankStatementLine;
use App\Finance\Treasury\Entity\TreasurySettings;
use App\Finance\Treasury\Enum\BankStatementLineStatus;
use App\Finance\Treasury\Service\PerimetreEtablissementsResolver;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * GET `/finance/treasury/discrepancies` (§0.8 du plan, US-TRE-09) — requête **live**, indépendante du
 * drapeau d'idempotence d'émission `discrepancyNotifiedAt` (§0.9) : `status IN (unmatched, suggested)`
 * **et** `createdAt < now − unmatchedAlertDelayDays`, quel que soit l'état de la notification déjà émise
 * ou non — une ligne apparaît au tableau de bord dès le délai dépassé, même avant le passage du cron.
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class DiscrepancyDashboardProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PerimetreEtablissementsResolver $perimetreResolver,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $etablissements = $this->perimetreResolver->etablissementsAutorises();

        /** @var list<BankStatementLine> $lignes */
        $lignes = $this->em->createQueryBuilder()
            ->select('l')
            ->from(BankStatementLine::class, 'l')
            ->innerJoin('l.statementImport', 'si')
            ->innerJoin('si.bankAccount', 'c')
            ->andWhere('c.establishment IN (:etablissements)')
            ->andWhere('l.status IN (:statuts)')
            ->setParameter('etablissements', $etablissements, ArrayParameterType::BINARY)
            ->setParameter('statuts', [BankStatementLineStatus::Unmatched, BankStatementLineStatus::Suggested])
            ->getQuery()->getResult();

        $delaisParEtablissement = [];
        $maintenant = new \DateTimeImmutable();

        $resultat = [];
        foreach ($lignes as $ligne) {
            $compte = $ligne->getStatementImport()?->getBankAccount();
            $idEtablissement = (string) $compte?->getEstablishment()?->getId();
            if (!isset($delaisParEtablissement[$idEtablissement])) {
                $settings = $compte?->getEstablishment() !== null
                    ? $this->em->getRepository(TreasurySettings::class)->findOneBy(['establishment' => $compte->getEstablishment()->getId()])
                    : null;
                $delaisParEtablissement[$idEtablissement] = $settings?->getUnmatchedAlertDelayDays() ?? TreasurySettings::DEFAULT_UNMATCHED_ALERT_DELAY_DAYS;
            }

            $seuil = $maintenant->modify(sprintf('-%d days', $delaisParEtablissement[$idEtablissement]));
            if ($ligne->getCreatedAt() >= $seuil) {
                continue;
            }

            $resultat[] = [
                'id' => (string) $ligne->getId(),
                'bankAccountId' => (string) $compte?->getId(),
                'operationDate' => $ligne->getOperationDate()?->format('Y-m-d'),
                'label' => $ligne->getLabel(),
                'amount' => $ligne->getAmount(),
                'status' => $ligne->getStatus()->value,
                'unmatchedSinceDays' => $ligne->getCreatedAt()->diff($maintenant)->days,
            ];
        }

        return new JsonResponse($resultat);
    }
}
