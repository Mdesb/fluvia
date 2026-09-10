<?php

declare(strict_types=1);

namespace App\Sport\Command;

use App\Membership\Entity\Membership;
use App\Membership\Enum\MembershipStatus;
use App\Membership\Enum\TermRenewalMode;
use App\Membership\Service\SubscriptionTermHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * LES ABONNEMENTS ARRIVES A LEUR TERME, TRAITES SELON CE QUE LEUR FORMULE PREVOIT.
 *
 * Avant : rien ne les traitait. Les echeances s'arretaient a la fin d'engagement, le prelevement
 * cessait, le statut restait « actif » et l'acces restait valide. L'adherent continuait d'entrer
 * gratuitement, indefiniment.
 *
 * ⚠ `--dry-run` D'ABORD, ET CE N'EST PAS UNE POLITESSE. Cette commande reconduit des engagements et
 * coupe des acces. Sur un parc reel, son premier passage traite tout l'arriere en une fois : c'est
 * pour cela qu'elle est declaree `safeOnFirstRun: false` au catalogue, et que l'ordonnanceur refuse
 * de la lancer seule tant qu'un humain n'a pas revendique la supervision (D109).
 */
#[AsCommand(
    name: 'sport:abonnements:traiter-terme',
    description: "Traite les abonnements arrives au terme de leur engagement, selon leur formule.",
)]
final class ProcessSubscriptionTermsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SubscriptionTermHandler $handler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Dit ce qui serait fait, sans le faire.')
            ->addOption('le', null, InputOption::VALUE_REQUIRED, "Date de reference (ISO), pour rejouer un jour precis.");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $le = $input->getOption('le');
        $maintenant = \is_string($le) && $le !== ''
            ? new \DateTimeImmutable($le)
            : new \DateTimeImmutable();

        $dus = $this->em->getRepository(Membership::class)
            ->createQueryBuilder('a')
            ->andWhere('a.statut = :actif')
            ->andWhere('a.dateFinEngagement <= :maintenant')
            ->setParameter('actif', MembershipStatus::Actif)
            ->setParameter('maintenant', $maintenant)
            ->getQuery()
            ->getResult();

        if ($dus === []) {
            $io->success('Aucun abonnement arrive a son terme.');

            return Command::SUCCESS;
        }

        $comptes = [];
        $implicites = 0;

        foreach ($dus as $abonnement) {
            $mode = TermRenewalMode::forFormule($abonnement->getFormule());

            if (!TermRenewalMode::isExplicit($abonnement->getFormule())) {
                ++$implicites;
            }

            if ($input->getOption('dry-run')) {
                $io->writeln(sprintf(
                    '  <comment>au terme</comment> %s — %s',
                    $abonnement->getId(),
                    $mode->libelle(),
                ));
                $comptes['simule'] = ($comptes['simule'] ?? 0) + 1;
                continue;
            }

            $resultat = $this->handler->process($abonnement, $maintenant);
            $comptes[$resultat] = ($comptes[$resultat] ?? 0) + 1;
        }

        foreach ($comptes as $quoi => $combien) {
            $io->writeln(sprintf('  %-24s %d', $quoi, $combien));
        }

        // ⚠ ON NOMME CE QUI A ETE TRAITE PAR DEFAUT PLUTOT QUE PAR CHOIX.
        //
        // Une formule sans `modeAuTerme` retombe sur « mensuel ». C'est le repli le moins nuisible,
        // pas une decision : le taire ferait passer un defaut de configuration pour un reglage
        // voulu, et personne ne saurait qu'il y a un choix a faire.
        if ($implicites > 0) {
            $io->warning(sprintf(
                "%d abonnement(s) traites au repli « mensuel » : leur formule ne declare aucun "
                . "`modeAuTerme`. Ce n'est pas un choix, c'est une absence de choix.",
                $implicites,
            ));
        }

        // ⚠ « sans-montant » N'EST PAS UN SUCCES SILENCIEUX. Un abonnement sans aucune echeance ne
        // permet pas d'etablir ce qu'il porte : on refuse de prolonger plutot que d'inventer un
        // prix. Il reste donc au terme, et il faut le savoir.
        if (($comptes['sans-montant'] ?? 0) > 0) {
            $io->warning(sprintf(
                "%d abonnement(s) n'ont AUCUNE echeance : impossible d'etablir leur montant, ils "
                . "restent a leur terme et ne sont ni reconduits ni suspendus.",
                $comptes['sans-montant'],
            ));
        }

        return Command::SUCCESS;
    }
}
