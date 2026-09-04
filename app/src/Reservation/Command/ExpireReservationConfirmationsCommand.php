<?php

declare(strict_types=1);

namespace App\Reservation\Command;

use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\ConfirmationExpiry;
use App\Reservation\Enum\StatutReservation;
use App\Reservation\Service\ResolveurRegleAnnulation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * CE QU'IL ADVIENT D'UNE RÉSERVATION QUE PERSONNE N'A CONFIRMÉE — R15 (a), seconde moitié.
 *
 * Sans cette tâche, une réservation entrée en « à confirmer » n'en sortirait jamais toute seule et
 * le créneau resterait bloqué indéfiniment. C'est pourquoi §8.8 bis interdisait d'activer un délai
 * de confirmation avant qu'elle existe.
 *
 * ── ⚠ LE COMPORTEMENT EST CELUI DE L'EXPLOITANT, PAS LE MIEN ────────────────────────────────────
 *
 * Maxime : « ces décisions sont des décisions **métier**, il faut laisser le choix à l'exploitant. »
 * Trois comportements, aucun imposé : `release`, `keep`, `release_and_charge`.
 *
 * ⚠ ET IL EST RÉSOLU AU MOMENT DE L'EXPIRATION, PAS FIGÉ À LA RÉSERVATION — contrairement à
 * l'échéance, qui l'est. La distinction est voulue : l'échéance a été **annoncée** au joueur et ne
 * doit pas bouger sous ses pieds ; le comportement à l'expiration est la politique de la maison,
 * et un exploitant qui la change veut qu'elle s'applique.
 *
 * ── ⚠ `release_and_charge` N'EST PAS ENCORE BRANCHÉ, ET LE DIT ─────────────────────────────────
 *
 * Facturer demande la machine de `FacturationNoShow` et ses stratégies. La brancher à moitié
 * produirait une facture qu'aucune vente ne soutient. Le créneau est donc libéré — c'est la moitié
 * sûre — et la commande **compte et annonce** ceux qui auraient dû être facturés. Un décompte
 * visible vaut mieux qu'un silence qui ressemble à un succès.
 */
#[AsCommand(
    name: 'reservation:confirmations:expirer',
    description: 'Traite les réservations non confirmées à l\'échéance, selon le choix de l\'exploitant (R15 a).',
)]
final class ExpireReservationConfirmationsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ResolveurRegleAnnulation $resolveurRegle,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        // ⚠ UN PLAFOND, PARCE QU'UN PREMIER PASSAGE PEUT RATTRAPER DES SEMAINES D'ARRIÉRÉ.
        // Même précaution que `crm:rgpd:alerter-delai` : on veut pouvoir regarder ce que ça fait
        // avant de le laisser tout traiter d'un coup.
        $this->addOption('plafond', null, InputOption::VALUE_REQUIRED, 'Nombre maximum de réservations traitées.', '500');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $plafond = max(1, (int) $input->getOption('plafond'));

        $compte = $this->expirer(new \DateTimeImmutable(), $plafond);

        $io->success(sprintf(
            '%d libérée(s), %d gardée(s) pour décision, %d sans règle applicable.',
            $compte['liberees'],
            $compte['gardees'],
            $compte['sans_regle'],
        ));

        if ($compte['a_facturer'] > 0) {
            $io->warning(sprintf(
                '%d réservation(s) auraient dû être FACTURÉES à l\'expiration (release_and_charge). '
                . 'Le créneau a été libéré ; la facturation n\'est pas branchée. À traiter à la main.',
                $compte['a_facturer'],
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * @return array{liberees: int, gardees: int, a_facturer: int, sans_regle: int}
     */
    public function expirer(\DateTimeImmutable $maintenant, int $plafond = 500): array
    {
        // ⚠ ON FILTRE SUR LES DEUX DATES **ET** SUR LE STATUT. Le statut seul ne suffirait pas :
        // une réservation annulée entre-temps pourrait le porter encore. Les deux dates seules ne
        // suffiraient pas non plus : elles restent posées après la confirmation.
        /** @var list<Reservation> $candidates */
        $candidates = $this->em->getRepository(Reservation::class)->createQueryBuilder('r')
            ->andWhere('r.statut = :attente')
            ->andWhere('r.confirmationDueAt IS NOT NULL')
            ->andWhere('r.confirmationDueAt < :maintenant')
            ->andWhere('r.confirmedAt IS NULL')
            ->setParameter('attente', StatutReservation::AConfirmer->value)
            ->setParameter('maintenant', $maintenant, 'datetime_immutable')
            ->setMaxResults($plafond)
            ->getQuery()
            ->getResult();

        $compte = ['liberees' => 0, 'gardees' => 0, 'a_facturer' => 0, 'sans_regle' => 0];

        foreach ($candidates as $reservation) {
            $creneau = $reservation->getCreneau();
            $regle = $creneau === null ? null : $this->resolveurRegle->resoudre($creneau);
            $comportement = $regle?->getConfirmationExpiry();

            if ($comportement === null) {
                // ⚠ AUCUN COMPORTEMENT DÉCLARÉ : ON NE DEVINE PAS. Libérer par défaut annulerait
                // des réservations qu'aucun exploitant n'a demandé d'annuler ; garder par défaut
                // est le choix qui ne détruit rien. On le COMPTE, pour que ça se voie.
                ++$compte['sans_regle'];
                continue;
            }

            match ($comportement) {
                ConfirmationExpiry::Keep => $compte['gardees']++,
                ConfirmationExpiry::Release => $this->liberer($reservation, $compte),
                ConfirmationExpiry::ReleaseAndCharge => $this->libererEtCompterAFacturer($reservation, $compte),
            };
        }

        $this->em->flush();

        return $compte;
    }

    /** @param array{liberees: int, gardees: int, a_facturer: int, sans_regle: int} $compte */
    private function liberer(Reservation $reservation, array &$compte): void
    {
        $reservation->setStatut(StatutReservation::AnnuleeLibre);
        ++$compte['liberees'];
    }

    /** @param array{liberees: int, gardees: int, a_facturer: int, sans_regle: int} $compte */
    private function libererEtCompterAFacturer(Reservation $reservation, array &$compte): void
    {
        $this->liberer($reservation, $compte);
        ++$compte['a_facturer'];
    }
}
