<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Crm\Entity\Client;
use App\Crm\Service\GeographicRegionResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Remplit la région géographique des clients déjà en base.
 *
 * `Client::setAdresse()` la calcule à chaque écriture — donc tout client créé ou modifié depuis la
 * migration la porte déjà. Cette commande ne concerne que ceux qui n'ont pas été touchés depuis.
 *
 * ── ⚠ CONSTAT PAR DÉFAUT, ÉCRITURE SUR DEMANDE ────────────────────────────────────────────────
 *
 * Même pli que `organisation:reprendre-profils-compta`. Une commande de reprise qui écrit dès qu'on
 * l'appelle est une commande qu'on n'ose plus lancer pour regarder — et qu'on finit par lancer à
 * l'aveugle le jour où il le faut vraiment.
 *
 * ── ELLE NE RECALCULE PAS CE QUI EST DÉJÀ POSÉ, ET C'EST DÉLIBÉRÉ ─────────────────────────────
 *
 * Un client dont la région est déjà remplie est passé par le setter : sa valeur est à jour par
 * construction. La repasser ne changerait rien dans le cas normal — et masquerait le seul cas
 * intéressant, celui où le découpage administratif aurait changé. Ce jour-là, `--tout` existe pour
 * le dire explicitement plutôt que de le faire à chaque passage sans qu'on le sache.
 */
#[AsCommand(
    name: 'crm:reprendre-regions-geographiques',
    description: 'Déduit la région géographique des clients qui n’en ont pas encore.',
)]
final class ReprendreRegionsGeographiquesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GeographicRegionResolver $resolveur,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('ecrire', null, InputOption::VALUE_NONE, 'Applique. Sans cette option : constat seulement.')
            ->addOption('tout', null, InputOption::VALUE_NONE, 'Recalcule aussi les clients dont la région est déjà posée (découpage administratif modifié).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $ecrire = (bool) $input->getOption('ecrire');
        $tout = (bool) $input->getOption('tout');

        $clients = $this->em->getRepository(Client::class)->createQueryBuilder('c')
            ->andWhere('c.adresse IS NOT NULL')
            ->getQuery()
            ->toIterable();

        $vus = 0;
        $poses = 0;
        $sansRattachement = 0;
        $parRegion = [];

        foreach ($clients as $client) {
            \assert($client instanceof Client);
            $vus++;

            if (!$tout && $client->getRegionGeographique() !== null) {
                continue;
            }

            $region = $this->resolveur->pourAdresse($client->getAdresse());

            if ($region === $client->getRegionGeographique()) {
                if ($region === null) {
                    $sansRattachement++;
                }
                continue;
            }

            // ⚠ UNE RÉGION QUI DEVIENT NULLE DOIT ÊTRE EFFACÉE, pas laissée en place. Le cas arrive
            // en `--tout` quand une adresse est passée à l'étranger : garder l'ancienne ferait
            // compter un client français qui n'y habite plus. C'est exactement la donnée périmée que
            // le stockage devait éviter, et elle ne se verrait dans aucun écran.
            if ($region === null) {
                $sansRattachement++;
            }

            $poses++;
            // ⚠ On ne compte QUE les régions posées. `$parRegion[null]` produirait une entrée à clé
            // vide — une ligne muette dans le tableau, indiscernable d'une région sans nom.
            if ($region !== null) {
                $parRegion[$region] = ($parRegion[$region] ?? 0) + 1;
            }

            if ($ecrire) {
                // ⚠ On repasse par le setter d'adresse : c'est LUI qui pose la région, et le faire
                // ici en second endroit ferait diverger les deux calculs au premier correctif.
                $client->setAdresse($client->getAdresse());
            }
        }

        if ($ecrire) {
            $this->em->flush();
        }

        $io->title('Régions géographiques des clients');
        $io->writeln(sprintf('  %d client(s) avec une adresse.', $vus));
        $io->writeln(sprintf('  %d changement(s) : région posée, ou effacée si l’adresse n’est plus rattachable.', $poses));

        // ⚠ ON DIT CE QU'ON N'A PAS SU RATTACHER, ET ON NE LE TAIT PAS. Un compte qui n'annonce que
        // ses succès laisse croire à une couverture complète. Ces clients ont une adresse et aucune
        // région : code étranger, code invalide, ou code postal absent de l'adresse.
        if ($sansRattachement > 0) {
            $io->writeln(sprintf('  ⚠ %d adresse(s) non rattachable(s) — étrangères, ou code postal absent ou invalide.', $sansRattachement));
        }

        if ($parRegion !== []) {
            arsort($parRegion);
            $io->writeln('');
            foreach ($parRegion as $region => $nombre) {
                $io->writeln(sprintf('    %-30s %d', $region, $nombre));
            }
        }

        if (!$ecrire) {
            $io->newLine();
            $io->note('Constat seulement. Relance avec --ecrire pour appliquer.');
        }

        return Command::SUCCESS;
    }
}
