<?php

declare(strict_types=1);

namespace App\RevenueRecovery\Command;

use App\RevenueRecovery\Service\RecoveryEngine;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `revenue-recovery:attempts:send` (RG-RR-03, plan-revenue-recovery.md §3/T5) — envoie les
 * `RecoveryAttempt` `Pending` échues, patron `App\Boutique\Command\LibererPaniersExpiresCommand`.
 * Revérifie le consentement du canal et l'état actif de la séquence **à l'exécution** (cas limite §11
 * spec), délègue entièrement à `RecoveryEngine::sendDueAttempts()`.
 */
#[AsCommand(name: 'revenue-recovery:attempts:send', description: 'Envoie les tentatives de relance échues (RG-RR-03).')]
final class SendDueRecoveryAttemptsCommand extends Command
{
    public function __construct(
        private readonly RecoveryEngine $engine,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $resultat = $this->engine->sendDueAttempts();

        $io->success(sprintf(
            '%d tentative(s) envoyée(s), %d sautée(s) (consentement/contact absent), %d annulée(s) (dossier/séquence inactif), %d échouée(s).',
            $resultat['sent'],
            $resultat['skipped'],
            $resultat['cancelled'],
            $resultat['failed'],
        ));

        return Command::SUCCESS;
    }
}
