<?php

declare(strict_types=1);

namespace App\Personnel\Command;

use App\Personnel\Entity\AffectationTravail;
use App\Personnel\Enum\StatutAffectationTravail;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Les agents planifiés dont la qualification aura expiré le jour du créneau.
 *
 * **Le seul cas qui survit au contrôle, et c'est celui que personne ne regarde.**
 *
 * `AffecterEmployeProcessor` refuse déjà d'affecter un employé sans qualification valide (CA-5) : on ne
 * peut pas mettre un agent non qualifié au planning. Ce refus donne à tout le monde la certitude que le
 * planning est valable — **et c'est précisément ce qui rend l'autre cas invisible.**
 *
 * Une qualification est vérifiée **à la date d'affectation**. Un maître-nageur dont le brevet expire
 * entre le moment où le planning est monté et le jour du créneau reste au planning, et **rien ne le
 * dit** : ni le refus, qui a déjà eu lieu et a laissé passer, ni le calcul de `RosterHebdomadaire`, qui
 * ne s'exécute que si quelqu'un ouvre le planning ce jour-là.
 *
 * Autrement dit : l'information existe, elle est juste, et **elle n'atteint personne**. Un responsable
 * ne le découvrira ni le jour même ni le lendemain — mais lors d'un contrôle, ou d'un accident.
 *
 * Relevé par `claude-H` en instrumentant les signaux muets du produit : `RosterHebdomadaire` publie
 * `qualificationManquanteOuExpiree`, et aucun écran ne l'affiche. Sa remarque sur la piscine décide de
 * la gravité — *le seuil POSS compte des baigneurs, celui-ci compte des surveillants qui n'ont pas le
 * droit de surveiller.*
 *
 * **Pourquoi une commande et pas seulement un écran.** Un écran ne montre que ce qu'on ouvre. Le cas
 * dangereux est celui d'un planning monté il y a trois semaines que plus personne ne rouvre — la
 * qualification y expire sans témoin. La commande, elle, regarde même quand personne ne regarde.
 *
 * **Sûre au premier passage** : elle lit et rapporte, elle n'écrit rien et n'envoie rien. Un arriéré
 * traité d'un coup est exactement le rattrapage qu'on veut — c'est la catégorie 1 du critère (nettoyage
 * ou constat d'état interne), et la seule des trois qui n'exige pas de supervision.
 *
 *     php bin/console personnel:qualifications:verifier
 *     php bin/console personnel:qualifications:verifier --jours=30
 */
#[AsCommand(
    name: 'personnel:qualifications:verifier',
    description: 'Signale les agents planifiés dont la qualification aura expiré le jour du créneau.',
)]
final class CheckQualificationsCommand extends Command
{
    private const HORIZON_PAR_DEFAUT = 14;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'jours',
            null,
            InputOption::VALUE_REQUIRED,
            'Horizon en jours à partir d\'aujourd\'hui.',
            (string) self::HORIZON_PAR_DEFAUT,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jours = max(1, (int) $input->getOption('jours'));
        $maintenant = new \DateTimeImmutable();
        $horizon = $maintenant->modify(sprintf('+%d days', $jours));

        /** @var list<AffectationTravail> $affectations */
        $affectations = $this->em->createQueryBuilder()
            ->select('a')
            ->from(AffectationTravail::class, 'a')
            ->join('a.creneauTravail', 'c')
            ->andWhere('a.statut != :annulee')
            ->andWhere('c.debut >= :maintenant')
            ->andWhere('c.debut <= :horizon')
            // Sans qualification utilisée, il n'y avait rien à exiger : le créneau n'en demandait pas.
            ->andWhere('a.qualificationUtilisee IS NOT NULL')
            ->setParameter('annulee', StatutAffectationTravail::Annulee)
            ->setParameter('maintenant', $maintenant)
            ->setParameter('horizon', $horizon)
            ->orderBy('c.debut', 'ASC')
            ->getQuery()
            ->getResult();

        $perimes = [];

        foreach ($affectations as $affectation) {
            $creneau = $affectation->getCreneauTravail();
            $qualification = $affectation->getQualificationUtilisee();
            $debut = $creneau?->getDebut();

            if ($qualification === null || $debut === null) {
                continue;
            }

            // La question n'est pas « la qualification est-elle valide aujourd'hui » mais « le sera-t-elle
            // LE JOUR DU CRÉNEAU ». Un brevet qui expire jeudi laisse le créneau de vendredi découvert,
            // et c'est aujourd'hui qu'il faut le savoir — pas vendredi.
            if ($qualification->estValideA($debut)) {
                continue;
            }

            $employe = $affectation->getEmploye();

            $perimes[] = sprintf(
                '  %s · %s · %s — %s',
                $debut->format('d/m/Y H:i'),
                $creneau?->getLibellePoste() ?? 'poste non précisé',
                trim(($employe?->getPrenom() ?? '') . ' ' . ($employe?->getNom() ?? '')) ?: 'employé inconnu',
                $qualification->getType()?->value ?? 'qualification inconnue',
            );
        }

        if ($perimes === []) {
            $output->writeln(sprintf(
                'Qualifications : OK — %d affectation(s) examinée(s) sur %d jour(s), aucune expirée au jour du créneau.',
                \count($affectations),
                $jours,
            ));

            return Command::SUCCESS;
        }

        // Le compte d'abord : c'est lui qu'on lit quand la sortie défile.
        $output->writeln('');
        $output->writeln(sprintf(
            '⚠ %d agent(s) planifié(s) avec une qualification expirée AU JOUR DU CRÉNEAU :',
            \count($perimes),
        ));
        $output->writeln('');

        foreach ($perimes as $ligne) {
            $output->writeln($ligne);
        }

        $output->writeln('');
        $output->writeln('  Ces affectations ont été acceptées : la qualification était valide le jour où');
        $output->writeln('  le planning a été monté. Elle ne le sera plus le jour du créneau.');
        $output->writeln('');
        $output->writeln('  Renouvelle la qualification, ou remplace l\'agent sur le créneau.');

        // Un compte rendu n'est pas un échec : la commande a fait son travail. Un code d'erreur ferait
        // rougir un ordonnanceur pour une information qu'il doit transmettre, pas traiter.
        return Command::SUCCESS;
    }
}
