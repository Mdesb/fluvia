<?php

declare(strict_types=1);

namespace App\Padel\Command;

use App\Padel\Entity\ReservationPadel;
use App\Padel\Enum\StatutPartieOuverte;
use App\Padel\Service\RepartitionSurcoutHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `padel:parties:maintenir-a-3` (RG-PADEL-03, CA-4, décision actée « Partie ouverte 3/4 ») : à l'heure
 * du créneau, une partie ouverte encore à 3 joueurs (le 4ᵉ non trouvé) est **maintenue** — jamais
 * annulée pour ce seul motif — et le surcoût est réparti entre les 3 présents.
 */
#[AsCommand(name: 'padel:parties:maintenir-a-3', description: 'Maintient à 3 les parties ouvertes non complétées à l\'heure du créneau et répartit le surcoût (CA-4).')]
final class MaintienPartieA3Command extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RepartitionSurcoutHandler $repartition,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $traitees = $this->traiter(new \DateTimeImmutable());
        $io->success(sprintf('%d partie(s) ouverte(s) maintenue(s) à 3.', $traitees));

        return Command::SUCCESS;
    }

    public function traiter(\DateTimeImmutable $maintenant): int
    {
        /** @var list<ReservationPadel> $parties */
        $parties = $this->em->getRepository(ReservationPadel::class)->createQueryBuilder('rp')
            ->andWhere('rp.ouverte = true')
            ->andWhere('rp.statutPartie = :ouverte')
            ->setParameter('ouverte', StatutPartieOuverte::Ouverte->value)
            ->getQuery()
            ->getResult();

        $traitees = 0;
        foreach ($parties as $partie) {
            $creneau = $partie->getReservation()?->getCreneau();
            if ($creneau === null || $creneau->getDebut() > $maintenant) {
                continue;
            }
            if ($this->repartition->appliquerSiApplicable($partie)) {
                ++$traitees;
            }
        }

        return $traitees;
    }
}
