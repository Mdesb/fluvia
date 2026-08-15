<?php

declare(strict_types=1);

namespace App\Compta\Command;

use App\Compta\Entity\ProfilExploitant;
use App\Compta\Service\RepriseMensuellePcaHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reprise mensuelle du PCA prorata temporis (US-L4-05) — commande planifiée (§4 du plan).
 */
#[AsCommand(name: 'compta:pca:reprise-mensuelle', description: 'Génère les reprises PCA prorata temporis du mois pour tous les profils exploitants.')]
final class RepriseMensuellePcaCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RepriseMensuellePcaHandler $handler,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<ProfilExploitant> $profils */
        $profils = $this->em->getRepository(ProfilExploitant::class)->findAll();

        $total = 0;
        foreach ($profils as $profil) {
            $total += $this->handler->reprendre($profil);
        }

        $io->success(sprintf('%d reprise(s) PCA générée(s).', $total));

        return Command::SUCCESS;
    }
}
