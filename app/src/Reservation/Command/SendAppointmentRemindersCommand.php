<?php

declare(strict_types=1);

namespace App\Reservation\Command;

use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\StatutReservation;
use App\Reservation\Notification\AppointmentReminderMailer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Envoie le rappel des rendez-vous à venir. Une prestation décide de son propre délai
 * (`Activite::rappelHeuresAvant`) : un massage de 90 minutes se rappelle la veille, une retouche de
 * quinze minutes deux heures avant.
 *
 * ── ⚠ POURQUOI CETTE COMMANDE NE REGARDE JAMAIS LE PASSÉ ─────────────────────────────────────────
 *
 * `ScheduledTask::$safeOnFirstRun` existe parce qu'une commande qui balaie sans borne de date
 * déclenche, à son premier passage, une action pour CHAQUE enregistrement de l'historique. Pour un
 * rappel, ce serait un courriel à tous les clients de tous les rendez-vous écoulés — la pire
 * première impression possible pour un module qu'on vient d'activer.
 *
 * La borne est donc double, et elle est dans la requête, pas dans un commentaire :
 *   - `creneau.debut > maintenant` : jamais un rendez-vous passé ;
 *   - `creneau.debut <= maintenant + FENETRE_MAX_HEURES` : jamais plus loin que le plus long délai
 *     de rappel qu'une prestation puisse déclarer.
 *
 * ⚠ ET LA TÂCHE EST DÉCLARÉE `safeOnFirstRun: false` MALGRÉ CES BORNES. Un premier passage envoie
 * de vrais messages à de vraies personnes : c'est irréversible et visible du dehors. Le
 * `--simuler` ci-dessous existe pour que ce premier passage se regarde avant de partir.
 *
 * ── ⚠ L'ESTAMPILLE EST CE QUI EMPÊCHE LE DOUBLE ENVOI ────────────────────────────────────────────
 *
 * `Reservation::$reminderSentAt` est posée APRÈS un envoi réussi, et seule une valeur nulle rend un
 * rendez-vous candidat. Sans elle, l'ordonnanceur rappellerait le même client tous les quarts
 * d'heure jusqu'à son rendez-vous.
 *
 * ⚠ UN CONTACT INCONNU N'EST PAS UN ÉCHEC ET NE S'ESTAMPILLE PAS NON PLUS : le mailer rend `false`,
 * on ne marque rien, et si l'adresse du client arrive demain le rappel partira. Estampiller ce cas
 * ferait taire définitivement un rappel qui aurait fini par être possible.
 */
#[AsCommand(
    name: 'reservation:reminders:send',
    description: 'Envoie le rappel des rendez-vous dont l\'échéance de rappel est atteinte.',
)]
final class SendAppointmentRemindersCommand extends Command
{
    /**
     * La fenêtre la plus large qu'on interroge. Elle borne la REQUÊTE ; le délai réel de chaque
     * rendez-vous est celui de sa prestation, appliqué ensuite.
     *
     * Sept jours : au-delà, un rappel n'en est plus un.
     */
    private const FENETRE_MAX_HEURES = 168;

    /** Plafond par passage : un carnet de commandes exceptionnel ne doit pas devenir un envoi massif. */
    private const MAX_PAR_PASSAGE = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AppointmentReminderMailer $mailer,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'simuler',
            null,
            InputOption::VALUE_NONE,
            'Liste ce qui partirait, sans envoyer NI estampiller. Aucune écriture, aucun message.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simulation = (bool) $input->getOption('simuler');

        $maintenant = new \DateTimeImmutable();
        $borneHaute = $maintenant->modify(sprintf('+%d hours', self::FENETRE_MAX_HEURES));

        /** @var list<Reservation> $candidats */
        $candidats = $this->em->getRepository(Reservation::class)->createQueryBuilder('r')
            ->join('r.creneau', 'c')
            ->join('c.activite', 'a')
            ->andWhere('r.reminderSentAt IS NULL')
            ->andWhere('r.statut = :statut')
            // ⚠ LES DEUX BORNES QUI ÉVITENT L'ENVOI EN MASSE AU PREMIER PASSAGE.
            ->andWhere('c.debut > :maintenant')
            ->andWhere('c.debut <= :borneHaute')
            // Une prestation à 0 ne veut pas de rappel : c'est le défaut, donc rien ne change pour
            // les établissements existants tant que personne n'a rien réglé.
            ->andWhere('a.rappelHeuresAvant > 0')
            ->setParameter('statut', StatutReservation::Confirmee->value)
            ->setParameter('maintenant', $maintenant, 'datetime_immutable')
            ->setParameter('borneHaute', $borneHaute, 'datetime_immutable')
            ->orderBy('c.debut', 'ASC')
            ->setMaxResults(self::MAX_PAR_PASSAGE)
            ->getQuery()
            ->getResult();

        $envoyes = 0;
        $sansContact = 0;
        $pasEncore = 0;
        $echecs = 0;

        foreach ($candidats as $reservation) {
            $creneau = $reservation->getCreneau();
            $activite = $creneau?->getActivite();
            if ($creneau === null || $activite === null) {
                continue;
            }

            $echeance = $creneau->getDebut()->modify(sprintf('-%d hours', $activite->getRappelHeuresAvant()));
            if ($echeance > $maintenant) {
                ++$pasEncore;
                continue;
            }

            if ($simulation) {
                $io->writeln(sprintf(
                    '  %s · %s · %s',
                    $creneau->getDebut()->format('d/m/Y H:i'),
                    $activite->getLibelle(),
                    $reservation->getOrganisateur()?->getClient()?->getEmail() ?? '(aucun contact)',
                ));
                ++$envoyes;
                continue;
            }

            try {
                if (!$this->mailer->envoyerSiContactConnu($reservation)) {
                    ++$sansContact;
                    continue;
                }
            } catch (\Throwable $e) {
                // ⚠ ON N'ESTAMPILLE PAS UN ÉCHEC. Le rendez-vous reste candidat au cycle suivant ;
                // c'est voulu, un transport indisponible dix minutes ne doit pas perdre le rappel.
                ++$echecs;
                $this->logger->error('reservation.reminder.echec', [
                    'reservation' => (string) $reservation->getId(),
                    'erreur' => $e->getMessage(),
                ]);
                continue;
            }

            $reservation->setReminderSentAt($maintenant);
            ++$envoyes;
        }

        if (!$simulation && $envoyes > 0) {
            $this->em->flush();
        }

        if ($simulation) {
            $io->note(sprintf(
                'Simulation : %d rappel(s) partiraient, %d pas encore à échéance. Rien n\'a été envoyé ni estampillé.',
                $envoyes,
                $pasEncore,
            ));

            return Command::SUCCESS;
        }

        $io->writeln(sprintf(
            '%d rappel(s) envoyé(s) · %d sans contact connu · %d pas encore à échéance · %d échec(s).',
            $envoyes,
            $sansContact,
            $pasEncore,
            $echecs,
        ));

        return Command::SUCCESS;
    }
}
