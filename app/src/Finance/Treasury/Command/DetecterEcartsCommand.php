<?php

declare(strict_types=1);

namespace App\Finance\Treasury\Command;

use App\Finance\Treasury\Entity\BankStatementLine;
use App\Finance\Treasury\Entity\TreasurySettings;
use App\Finance\Treasury\Enum\BankStatementLineStatus;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventBus;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `finance:treasury:detecter-ecarts` (§0.9 du plan, RG-TRE-09, CA-6) — parcourt les `BankStatementLine`
 * de comptes **actifs**, `status IN (unmatched, suggested)`, `createdAt < now −
 * TreasurySettings.unmatchedAlertDelayDays` (défaut 15 j), `discrepancyNotifiedAt IS NULL` (garde
 * d'idempotence — **sans elle, chaque passage du cron réémettrait l'événement** pour la même ligne
 * indéfiniment). Pour chaque ligne : émission `treasury.discrepancy_detected`,
 * `discrepancyNotifiedAt = now`. Tenant dérivé de `BankAccount.establishment` (D6), jamais du contexte
 * HTTP — la commande n'en a d'ailleurs aucun. À planifier via cron externe (D7), même patron que
 * `finance:expense-reports:resoudre-escalades` (FIN-3).
 *
 * Chaque ligne est traitée dans sa **propre** transaction (`wrapInTransaction`, flush + publish réunis) :
 * une ligne dont l'abonné échoue n'empêche pas les autres d'être notifiées (même correctif que
 * `EscaladeExpenseReportResolver`, FIN-3).
 */
#[AsCommand(
    name: 'finance:treasury:detecter-ecarts',
    description: 'Émet `treasury.discrepancy_detected` pour les lignes de relevé non rapprochées au-delà du délai paramétré.',
)]
final class DetecterEcartsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventBus $eventBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<BankStatementLine> $lignes */
        $lignes = $this->em->createQueryBuilder()
            ->select('l')
            ->from(BankStatementLine::class, 'l')
            ->innerJoin('l.statementImport', 'si')
            ->innerJoin('si.bankAccount', 'c')
            ->andWhere('l.status IN (:statuts)')
            ->andWhere('c.active = :actif')
            ->andWhere('l.discrepancyNotifiedAt IS NULL')
            ->setParameter('statuts', [BankStatementLineStatus::Unmatched, BankStatementLineStatus::Suggested])
            ->setParameter('actif', true)
            ->getQuery()->getResult();

        $maintenant = new \DateTimeImmutable();
        $delaisParEtablissement = [];
        $notifiees = 0;

        foreach ($lignes as $ligne) {
            $compte = $ligne->getStatementImport()?->getBankAccount();
            $etablissement = $compte?->getEstablishment();
            if ($compte === null || $etablissement === null) {
                continue;
            }

            $idEtablissement = (string) $etablissement->getId();
            if (!isset($delaisParEtablissement[$idEtablissement])) {
                $settings = $this->em->getRepository(TreasurySettings::class)->findOneBy(['establishment' => $etablissement->getId()]);
                $delaisParEtablissement[$idEtablissement] = $settings?->getUnmatchedAlertDelayDays() ?? TreasurySettings::DEFAULT_UNMATCHED_ALERT_DELAY_DAYS;
            }

            $seuil = $maintenant->modify(sprintf('-%d days', $delaisParEtablissement[$idEtablissement]));
            if ($ligne->getCreatedAt() >= $seuil) {
                continue;
            }

            $this->em->wrapInTransaction(function () use ($ligne, $compte, $etablissement, $maintenant): void {
                $ligne->setDiscrepancyNotifiedAt($maintenant);
                $this->em->persist($ligne);
                $this->em->flush();

                $this->eventBus->publish(new DomainEvent(
                    'treasury.discrepancy_detected',
                    new EventTenant($etablissement->getId()),
                    new EventSubject('BankStatementLine', (string) $ligne->getId()),
                    [
                        'bankAccountId' => (string) $compte->getId(),
                        'amountCents' => (int) round(((float) $ligne->getAmount()) * 100),
                        'unmatchedSinceDays' => $ligne->getCreatedAt()->diff($maintenant)->days,
                    ],
                ));
            });
            ++$notifiees;
        }

        $io->success(sprintf('%d écart(s) notifié(s) sur %d ligne(s) candidates.', $notifiees, \count($lignes)));

        return Command::SUCCESS;
    }
}
