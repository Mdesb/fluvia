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
 * **⚠ CETTE COMMANDE A D'ABORD ÉTÉ JUSTIFIÉE SUR UNE PRÉMISSE FAUSSE, ET LE TEST L'A DIT.**
 *
 * J'avais écrit qu'une qualification est vérifiée « à la date d'affectation », et que le cas dangereux
 * était donc un brevet expirant entre la planification et le créneau. **C'est faux.**
 * `AffecterEmployeProcessor::qualificationValide()` interroge `estValideA($debut)` — la validité **au
 * jour du créneau**. On ne peut pas planifier quelqu'un dont le brevet aura expiré.
 *
 * J'avais lu que le garde existait, pas ce qu'il comparait. **Vérifier qu'un contrôle est là n'est pas
 * vérifier ce qu'il contrôle** — la faute exacte que je corrigeais chez les autres depuis deux jours.
 * C'est le test qui m'a détrompé, en refusant l'affectation que je croyais possible.
 *
 * **Ce qui reste vrai, et que rien ne surveille.** L'affectation est vérifiée **une seule fois**, au
 * moment où on la crée. Rien ne la revoit ensuite : une qualification **révoquée**, **raccourcie** ou
 * **supprimée** après coup laisse l'affectation en place. Et `RosterHebdomadaire` ne recalcule que si
 * quelqu'un ouvre le planning ce jour-là — or le cas dangereux est celui d'un planning monté il y a
 * trois semaines que plus personne ne rouvre.
 *
 * Le cas est **plus étroit** que je ne l'avais écrit. Il n'est pas moins réel : une suspension, un
 * contrôle médical, une erreur de saisie corrigée raccourcissent une validité — et personne ne pense à
 * rapprocher ça d'un planning déjà monté.
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
