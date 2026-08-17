<?php

declare(strict_types=1);

namespace App\Personnel\Command;

use App\Personnel\Entity\BadgeStaff;
use App\Personnel\Entity\Employe;
use App\Personnel\Enum\StatutBadgeStaff;
use App\Personnel\Enum\StatutEmploye;
use App\Personnel\Service\RevocationBadgeHandler;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `personnel:traiter-echeances-sortie` (RG-PERSO-08, CA-10, T8 du plan) : pour tout `Employe` dont
 * `dateSortie` est atteinte (aujourd'hui ou passée) et dont `statut` n'est pas déjà `sorti`, bascule
 * `statut = sorti` et révoque **immédiatement** tout `BadgeStaff` actif (fin de contrat, déclencheur
 * 1 de RG-PERSO-08, via `RevocationBadgeHandler::revoquer()` = `BlocageSupportHandler::bloquer()`,
 * tracé).
 *
 * L'agent traçant l'action (`DeclarationPerteVol.agent`, non-nullable côté Acces — aucune
 * modification de ce champ socle) doit être fourni explicitement en argument (compte technique
 * dédié pour l'exécution planifiée, ex. cron), aucune notion d'« utilisateur système » implicite
 * n'existant dans le socle.
 */
#[AsCommand(name: 'personnel:traiter-echeances-sortie', description: 'Révoque les badges staff des employés dont la date de sortie est atteinte.')]
final class TraiterEcheancesSortieCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RevocationBadgeHandler $revocationHandler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('agentEmail', InputArgument::REQUIRED, 'Email du compte technique tracé comme auteur de la révocation.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $agent = $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => (string) $input->getArgument('agentEmail')]);
        if (!$agent instanceof Utilisateur) {
            $io->error('Compte technique introuvable.');

            return Command::FAILURE;
        }

        $aujourdhui = new \DateTimeImmutable('today');

        /** @var list<Employe> $employesSortants */
        $employesSortants = $this->em->getRepository(Employe::class)->createQueryBuilder('e')
            ->andWhere('e.dateSortie IS NOT NULL')
            ->andWhere('e.dateSortie <= :aujourdhui')
            ->andWhere('e.statut != :sorti')
            ->setParameter('aujourdhui', $aujourdhui, 'date_immutable')
            ->setParameter('sorti', StatutEmploye::Sorti->value)
            ->getQuery()->getResult();

        $badgesRevoques = 0;
        foreach ($employesSortants as $employe) {
            $employe->setStatut(StatutEmploye::Sorti);

            $badges = $this->em->getRepository(BadgeStaff::class)->findBy(['employe' => $employe, 'statut' => StatutBadgeStaff::Actif]);
            foreach ($badges as $badge) {
                \assert($badge instanceof BadgeStaff);
                $this->revocationHandler->revoquer($badge, 'Fin de contrat (dateSortie atteinte, RG-PERSO-08).', $agent);
                ++$badgesRevoques;
            }
        }
        $this->em->flush();

        $io->success(sprintf('%d employé(s) basculé(s) en sortie, %d badge(s) révoqué(s).', \count($employesSortants), $badgesRevoques));

        return Command::SUCCESS;
    }
}
