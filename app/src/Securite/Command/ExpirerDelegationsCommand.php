<?php

declare(strict_types=1);

namespace App\Securite\Command;

use App\Securite\Entity\DelegationDroit;
use App\Securite\Enum\StatutDelegation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `securite:delegations:expirer` (US-L7-07, CA-8, §2.5 plan-backoffice.md) : révoque
 * automatiquement les délégations dont `dateFin` est atteinte — idempotente, sans effet de bord
 * si aucune ligne. À planifier via un cron externe au code applicatif (§8/§9 plan-backoffice.md).
 */
#[AsCommand(
    name: 'securite:delegations:expirer',
    description: 'Passe au statut « expirée » les délégations de droits actives dont la date de fin est dépassée.',
)]
final class ExpirerDelegationsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $maintenant = new \DateTimeImmutable();

        $qb = $this->em->createQueryBuilder()
            ->select('d')
            ->from(DelegationDroit::class, 'd')
            ->where('d.statut = :active')
            ->andWhere('d.dateFin < :maintenant')
            ->setParameter('active', StatutDelegation::Active)
            ->setParameter('maintenant', $maintenant);

        $delegations = $qb->getQuery()->getResult();

        foreach ($delegations as $delegation) {
            \assert($delegation instanceof DelegationDroit);
            $delegation->setStatut(StatutDelegation::Expiree);
            $delegation->setDateRevocation($maintenant);
        }

        $this->em->flush();

        $io->success(sprintf('%d délégation(s) expirée(s).', \count($delegations)));

        return Command::SUCCESS;
    }
}
