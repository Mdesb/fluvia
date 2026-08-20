<?php

declare(strict_types=1);

namespace App\Finance\ExpenseReport\Command;

use App\Finance\ExpenseReport\Entity\ExpenseReport;
use App\Finance\ExpenseReport\Enum\ExpenseReportStatus;
use App\Finance\ExpenseReport\Service\EscaladeExpenseReportResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `finance:expense-reports:resoudre-escalades` (§0.3.2 du plan) — même patron exact que
 * `App\Autorisation\Command\ExpirerEscaladesCommand` : parcourt les `ExpenseReport` `submitted` dont
 * `escalationRequest` est renseigné, appelle `EscaladeExpenseReportResolver::resoudre()` pour chacune
 * (acteur `null` — « réaction en chaîne », `EventActor` absent sur `expense_report.approved`).
 *
 * **C'est le mécanisme qui garantit** RG-EXP-04 (« une fois approuvée par un superviseur, la note
 * passe à `approved` ») même si personne ne rappelle `POST .../finalize-escalade` — sans cette
 * commande, une note approuvée par escalade resterait `submitted` indéfiniment. À planifier via cron
 * externe (aucune infrastructure de planification embarquée, D7).
 *
 * Fonctionne **sans contexte HTTP actif** : `ContexteEtablissement::idActif()` retourne `null` en CLI,
 * sans faire échouer le rejeu (§0.3.2 — `ServiceAutorisation::evaluerRejeu()` ne dépend ni de
 * `Security::isGranted()` ni de `ContexteEtablissement`, vérifié dans le code).
 */
#[AsCommand(
    name: 'finance:expense-reports:resoudre-escalades',
    description: "Finalise les notes de frais dont l'escalade a été tranchée par un superviseur (approuvée, rejetée ou expirée).",
)]
final class ResoudreEscaladesExpenseReportCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EscaladeExpenseReportResolver $resolver,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $qb = $this->em->createQueryBuilder()
            ->select('r')
            ->from(ExpenseReport::class, 'r')
            ->where('r.status = :submitted')
            ->andWhere('r.escalationRequest IS NOT NULL')
            ->setParameter('submitted', ExpenseReportStatus::Submitted);

        /** @var list<ExpenseReport> $rapports */
        $rapports = $qb->getQuery()->getResult();

        $traitees = 0;
        foreach ($rapports as $rapport) {
            $avant = $rapport->getStatus();
            // `resoudre()` porte sa propre transaction/flush par note (D7 — chaque émission d'événement
            // reste dans SA transaction, une note en erreur n'annule pas les autres).
            $this->resolver->resoudre($rapport, null);
            if ($rapport->getStatus() !== $avant) {
                ++$traitees;
            }
        }

        $io->success(sprintf('%d note(s) de frais finalisée(s) sur %d en attente d\'escalade.', $traitees, \count($rapports)));

        return Command::SUCCESS;
    }
}
