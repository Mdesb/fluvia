<?php

declare(strict_types=1);

namespace App\Padel\Command;

use App\Padel\Entity\EvenementEclairage;
use App\Padel\Entity\RelaisEclairageTerrain;
use App\Padel\Entity\ReservationPadel;
use App\Padel\Enum\ActionEclairage;
use App\Padel\Service\PilotageEclairageHandler;
use App\Reservation\Enum\StatutReservation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `padel:eclairage:commander` (RG-PADEL-05, CA-11) : allume le relais à l'heure de début et l'éteint à
 * l'heure de fin de chaque réservation confirmée sur un terrain équipé.
 */
#[AsCommand(name: 'padel:eclairage:commander', description: 'Allume/éteint automatiquement le relais d\'éclairage sur la fenêtre réservée (CA-11).')]
final class CommanderEclairageCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PilotageEclairageHandler $handler,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $commandes = $this->commander(new \DateTimeImmutable());
        $io->success(sprintf('%d commande(s) d\'éclairage exécutée(s).', $commandes));

        return Command::SUCCESS;
    }

    public function commander(\DateTimeImmutable $maintenant): int
    {
        /** @var list<ReservationPadel> $reservations */
        $reservations = $this->em->getRepository(ReservationPadel::class)->createQueryBuilder('rp')
            ->getQuery()
            ->getResult();

        $commandes = 0;
        foreach ($reservations as $reservationPadel) {
            $reservation = $reservationPadel->getReservation();
            $terrain = $reservationPadel->getTerrain();
            $creneau = $reservation?->getCreneau();
            if ($reservation === null || $terrain === null || $creneau === null) {
                continue;
            }
            if (!\in_array($reservation->getStatut(), [StatutReservation::Confirmee, StatutReservation::Honoree], true)) {
                continue;
            }

            $relais = $this->em->getRepository(RelaisEclairageTerrain::class)->findOneBy(['terrain' => $terrain]);
            if ($relais === null) {
                continue;
            }

            if ($creneau->getDebut() <= $maintenant && !$this->evenementExiste($reservation, ActionEclairage::Allumage)) {
                if ($this->handler->declencher($relais, ActionEclairage::Allumage, $reservation) !== null) {
                    ++$commandes;
                }
            }

            if ($creneau->getFin() <= $maintenant && !$this->evenementExiste($reservation, ActionEclairage::Extinction)) {
                if ($this->handler->declencher($relais, ActionEclairage::Extinction, $reservation) !== null) {
                    ++$commandes;
                }
            }
        }

        return $commandes;
    }

    private function evenementExiste(\App\Reservation\Entity\Reservation $reservation, ActionEclairage $action): bool
    {
        return $this->em->getRepository(EvenementEclairage::class)->findOneBy([
            'reservation' => $reservation,
            'action' => $action,
        ]) !== null;
    }
}
