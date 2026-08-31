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
 *
 * **Le balayage est borné des deux côtés, et ce n'est pas une optimisation.** La requête ne filtre pas
 * sur une fenêtre : elle rapporte toutes les réservations padel de la base. Sans borne, la seule
 * condition `début <= maintenant` fait commander l'allumage de chaque réservation passée dépourvue
 * d'événement d'allumage — donc des projecteurs qui s'allument pour une partie terminée, à chaque
 * passage du planificateur. D'où les deux règles :
 *
 * 1. **on n'allume pas une partie déjà finie** (`fin > maintenant`) ;
 * 2. **on n'éteint que ce qu'on a allumé** (l'événement d'allumage doit exister).
 *
 * Elles rendent aussi la commande idempotente dans le temps, ce qui est ce que `EclairageTest` vérifie
 * — et ce qui manquait : le test échouait un jour sur sept, le lundi, quand la réservation de
 * démonstration des fixtures (`next tuesday`) tombait avant la fenêtre du test (`next monday`).
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

            $allume = $this->evenementExiste($reservation, ActionEclairage::Allumage);

            // On n'allume pas un terrain dont la partie est déjà terminée : sans cette borne, un
            // passage du planificateur rattrape *toute* réservation passée sans événement d'allumage
            // et commande physiquement les projecteurs pour une partie de la semaine dernière — à
            // chaque passage, puisque l'événement manquant ne se crée jamais.
            if ($creneau->getDebut() <= $maintenant && $creneau->getFin() > $maintenant && !$allume) {
                if ($this->handler->declencher($relais, ActionEclairage::Allumage, $reservation) !== null) {
                    ++$commandes;
                    $allume = true;
                }
            }

            // Et on n'éteint que ce qu'on a allumé. L'extinction d'un relais jamais commandé n'est pas
            // seulement inutile : elle produit un événement `extinction` sans `allumage`, ce qui rend
            // l'historique d'un terrain illisible pour l'exploitant qui cherche une panne.
            if ($allume && $creneau->getFin() <= $maintenant && !$this->evenementExiste($reservation, ActionEclairage::Extinction)) {
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
