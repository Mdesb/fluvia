<?php

declare(strict_types=1);

namespace App\Social\Command;

use App\Social\Service\SocialTokenKeyRotator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rechiffre les jetons du coffre social avec la clé active, et dit ce qu'il reste.
 *
 * Le mode d'emploi d'une rotation, dans cet ordre — c'est l'ordre qui compte :
 *
 * 1. Poser la nouvelle clé dans `SOCIAL_TOKEN_ENCRYPTION_KEY`, déplacer l'ancienne dans
 *    `SOCIAL_TOKEN_ENCRYPTION_KEYS_RETIRED` sous son ancien numéro, incrémenter
 *    `SocialTokenCipher::CURRENT_KEY_VERSION`.
 * 2. `social:token-key-status` — vérifier que tout est encore lisible.
 * 3. `social:rotate-token-key` autant de fois qu'il le faut, jusqu'à ce que le reste soit nul.
 * 4. **Seulement alors**, retirer l'ancienne clé de l'environnement.
 *
 * Retirer l'ancienne clé avant l'étape 3 rend illisibles les jetons non encore traités, et cela ne se
 * voit pas : rien ne casse tant que personne ne publie. C'est pourquoi la commande affiche toujours le
 * reste, même quand elle n'a rien eu à faire.
 */
#[AsCommand(
    name: 'social:rotate-token-key',
    description: 'Rechiffre les jetons sociaux avec la cle active (idempotent, reprenable).',
)]
final class RotateSocialTokenKeyCommand extends Command
{
    private const DEFAULT_LIMIT = 200;

    public function __construct(
        private readonly SocialTokenKeyRotator $rotator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Comptes traites par passage.', (string) self::DEFAULT_LIMIT)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compte sans rien ecrire.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));
        $dryRun = (bool) $input->getOption('dry-run');

        $rapport = $this->rotator->rotate($limit, $dryRun);

        $io->writeln(sprintf(
            '%s : %d compte(s) rechiffre(s), %d illisible(s), %d restant(s).',
            $dryRun ? 'Simulation' : 'Rotation',
            $rapport['rotated'],
            $rapport['failed'],
            $rapport['remaining'],
        ));

        if ($rapport['failed'] > 0) {
            // Un compte illisible ne se répare pas par une reprise : il faut soit remettre la clé qui
            // manque, soit reconnecter le compte. Le dire fort, parce que le décompte « restants »
            // n'atteindra jamais zéro tant qu'il est là.
            $io->warning(sprintf(
                '%d compte(s) illisible(s) : une cle retiree trop tot, ou une valeur corrompue. Ils resteront dans le decompte.',
                $rapport['failed'],
            ));
        }

        if ($rapport['remaining'] > 0) {
            $io->note('Il reste des comptes a traiter : relancez. Ne retirez aucune cle de l environnement avant que le reste soit nul.');
        } else {
            $io->success('Tous les jetons lisibles sont a la cle active.');
        }

        return Command::SUCCESS;
    }
}
