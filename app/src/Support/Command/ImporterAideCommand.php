<?php

declare(strict_types=1);

namespace App\Support\Command;

use App\Support\Service\ImporteurAideService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `support:importer-aide` (US-SUP-08, RG-SUP-07/08, §5.2 plan-support.md) : lit les fichiers de doc
 * vivante `docs/aide/<module>/<slug>.md`, upsert les `ArticleAide` par `cleImport`. Idempotente,
 * rejouable à chaque livraison de module. Délègue toute la logique à `ImporteurAideService`
 * (réutilisée par `POST /support/import/executer`), patron *service applicatif + fine commande CLI*
 * de `App\Securite\Command\ExpirerDelegationsCommand`.
 *
 * ⚠ Écart au plan (contrainte d'environnement) : le plan situe la doc vivante à `docs/aide/` **hors**
 * `app/` (racine dépôt) ; seul `./app` étant bind-monté dans le conteneur `php`
 * (`docker-compose.yml`), la convention est appliquée ici à `app/docs/aide/<module>/<slug>.md`
 * (`--chemin` par défaut = `%kernel.project_dir%/docs/aide`), pour rester lisible par la commande.
 */
#[AsCommand(
    name: 'support:importer-aide',
    description: 'Importe/synchronise la base de connaissance depuis la doc vivante docs/aide/<module>/<slug>.md (idempotent, rejouable).',
)]
final class ImporterAideCommand extends Command
{
    public function __construct(
        private readonly ImporteurAideService $importeur,
        private readonly string $cheminAideParDefaut,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('chemin', null, InputOption::VALUE_REQUIRED, 'Répertoire racine de la doc vivante', $this->cheminAideParDefaut)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Exécute la résolution/diff sans écrire en base')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Code de sortie non-zéro si au moins un fichier est en erreur (utilisable en CI)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $chemin = (string) $input->getOption('chemin');
        $dryRun = (bool) $input->getOption('dry-run');
        $strict = (bool) $input->getOption('strict');

        $resume = $this->importeur->executer($chemin, $dryRun);

        if ($resume->total() === 0) {
            $io->warning(sprintf('Aucun fichier de doc vivante trouvé sous "%s".', $chemin));
        } else {
            $io->table(
                ['Fichier', 'Résultat', 'Message'],
                array_map(static fn (array $ligne) => [$ligne['fichier'], $ligne['resultat'], $ligne['message'] ?? ''], $resume->lignes),
            );
        }

        $io->writeln(sprintf(
            '%s— %d créé(s), %d mis à jour, %d inchangé(s), %d en erreur (total %d).',
            $dryRun ? '[dry-run] ' : '',
            $resume->cree,
            $resume->maj,
            $resume->inchange,
            $resume->erreur,
            $resume->total(),
        ));

        if ($strict && $resume->erreur > 0) {
            $io->error('Import en erreur (--strict) : au moins un fichier invalide.');

            return Command::FAILURE;
        }

        $io->success('Import doc vivante terminé.');

        return Command::SUCCESS;
    }
}
