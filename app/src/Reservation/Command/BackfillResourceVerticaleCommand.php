<?php

declare(strict_types=1);

namespace App\Reservation\Command;

use App\Fonctionnalite\Service\Fonctionnalites;
use App\Fonctionnalite\State\VocabularyProvider;
use App\Platform\Module\ModuleRegistry;
use App\Reservation\Entity\Ressource;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `reservation:backfill-resource-verticale` (#100) : renseigne `Ressource.verticale` pour les
 * ressources qui ne l'ont pas encore, en la DÉRIVANT du métier unique de l'établissement.
 *
 * Le mot affiché par verticale (padel « Terrain », piscine « Bassin ») ne dépend de la ressource que
 * si celle-ci porte une `verticale`. Ce champ, ajouté au lot 1 (#123), n'était renseigné nulle part :
 * les écrans retombaient donc partout sur le défaut. Les ressources créées désormais par les
 * processeurs métier la portent (terrain padel → padel, visite guidée → musée) ; cette commande
 * comble l'EXISTANT.
 *
 * ── CE QU'ELLE FAIT, ET CE QU'ELLE NE FAIT PAS ─────────────────────────────────────────────────
 *
 * Elle ne pose une verticale QUE lorsque l'établissement n'a qu'UNE verticale active — la même règle
 * que le `courant` de l'API `/vocabulary`, via `VocabularyProvider::verticaleUnique`. Un établissement
 * MIXTE (padel + piscine) reste ambigu au niveau établissement : ses ressources gardent `verticale`
 * nulle, à régler une par une (saisie explicite sur la fiche ressource, écran Disponibilités). Elle ne
 * peut donc pas poser une valeur FAUSSE — au pire, elle s'abstient.
 *
 * Idempotente : ne touche que les ressources à `verticale` nulle. `--dry-run` liste sans écrire.
 */
#[AsCommand(
    name: 'reservation:backfill-resource-verticale',
    description: "Renseigne la verticale des ressources qui n'en ont pas, dérivée du métier unique de l'établissement.",
)]
final class BackfillResourceVerticaleCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Fonctionnalites $fonctionnalites,
        private readonly ModuleRegistry $registry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Liste ce qui serait renseigné, sans rien écrire.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $verticales = VocabularyProvider::verticalesAvecVocabulaire($this->registry);

        /** @var list<Ressource> $ressources */
        $ressources = $this->em->getRepository(Ressource::class)->findBy(['verticale' => null]);

        if ($ressources === []) {
            $io->success('Aucune ressource sans verticale : rien à faire.');

            return Command::SUCCESS;
        }

        // Le métier unique se calcule PAR établissement ; on met le résultat en cache pour ne pas
        // réinterroger les fonctionnalités à chaque ressource du même établissement.
        $verticaleParEtablissement = [];
        $poses = 0;
        $abstenus = 0;

        foreach ($ressources as $ressource) {
            $etablissement = $ressource->getEtablissement();
            if ($etablissement === null) {
                ++$abstenus;
                continue;
            }

            $cle = (string) $etablissement->getId();
            if (!\array_key_exists($cle, $verticaleParEtablissement)) {
                $verticaleParEtablissement[$cle] = VocabularyProvider::verticaleUnique(
                    $this->fonctionnalites->actives($etablissement),
                    $verticales,
                );
            }
            $verticale = $verticaleParEtablissement[$cle];

            if ($verticale === null) {
                ++$abstenus;
                continue;
            }

            if (!$dryRun) {
                $ressource->setVerticale($verticale);
            }
            ++$poses;
            $io->writeln(sprintf('  %s « %s » → %s', $dryRun ? '[à poser]' : '[posé]', $ressource->getLibelle(), $verticale));
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->success(sprintf(
            '%d ressource(s) %s ; %d laissée(s) sans verticale (établissement mixte ou sans verticale unique).',
            $poses,
            $dryRun ? 'seraient renseignées' : 'renseignées',
            $abstenus,
        ));

        return Command::SUCCESS;
    }
}
