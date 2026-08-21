<?php

declare(strict_types=1);

namespace App\Finance\Treasury\Command;

use App\Compta\Entity\LigneEcriture;
use App\Finance\Treasury\Entity\BankStatementLine;
use App\Finance\Treasury\Enum\BankStatementLineStatus;
use App\Finance\Treasury\Service\BankReconciliationSuggestionCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * `finance:treasury:suggerer-rapprochements` (§0.7 du plan) — persistance du statut `suggested` (4ᵉ
 * valeur de l'énumération, sinon jamais atteint) : parcourt les `BankStatementLine` `unmatched` d'un
 * compte **actif** (comptes inactifs exclus, §7 cas limite spec), calcule les candidats
 * (`BankReconciliationSuggestionCalculator`, lecture seule) — **exactement 1 candidate** -> `status =
 * suggested`, `suggestedLedgerLine` posé ; **0 ou 2+ candidates** -> reste `unmatched` (`GET …
 * /suggestions` reste disponible en direct dans les deux cas). À planifier via cron externe (D7, aucune
 * infrastructure de planification embarquée), même patron que
 * `finance:expense-reports:resoudre-escalades` (FIN-3).
 */
#[AsCommand(
    name: 'finance:treasury:suggerer-rapprochements',
    description: 'Pose le statut `suggested` sur les lignes de relevé non ambiguës (exactement 1 candidat).',
)]
final class SuggererRapprochementsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BankReconciliationSuggestionCalculator $calculator,
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
            ->andWhere('l.status = :unmatched')
            ->andWhere('c.active = :actif')
            ->setParameter('unmatched', BankStatementLineStatus::Unmatched)
            ->setParameter('actif', true)
            ->getQuery()->getResult();

        $suggerees = 0;
        foreach ($lignes as $ligne) {
            $candidats = $this->calculator->candidats($ligne);
            if (\count($candidats) !== 1) {
                continue;
            }

            $ligneEcriture = $this->em->find(LigneEcriture::class, Uuid::fromString($candidats[0]->ledgerLineId));
            if ($ligneEcriture === null) {
                continue;
            }

            $ligne->setStatus(BankStatementLineStatus::Suggested);
            $ligne->setSuggestedLedgerLine($ligneEcriture);
            $this->em->persist($ligne);
            ++$suggerees;
        }
        $this->em->flush();

        $io->success(sprintf('%d ligne(s) suggérée(s) sur %d ligne(s) non rapprochées examinées.', $suggerees, \count($lignes)));

        return Command::SUCCESS;
    }
}
