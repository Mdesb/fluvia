<?php

declare(strict_types=1);

namespace App\Social\Command;

use App\Social\Crypto\SocialTokenCipher;
use App\Social\Service\SocialTokenKeyRotator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Repartition des jetons du coffre social par version de cle.
 *
 * C'est la commande qu'on lance **avant** de retirer une cle de l'environnement. La lancer apres
 * reviendrait a decouvrir la perte au premier envoi, c'est-a-dire chez le client.
 *
 * Elle affiche des compteurs et rien d'autre : aucune valeur, aucun jeton, meme chiffre. Une rotation
 * est precisement le moment ou l'on est tente d'afficher « avant / apres » pour se rassurer, et
 * precisement celui ou cela ferait fuir tout le coffre dans un journal ou dans un ticket.
 */
#[AsCommand(
    name: 'social:token-key-status',
    description: 'Affiche la repartition des jetons sociaux par version de cle.',
)]
final class SocialTokenKeyStatusCommand extends Command
{
    public function __construct(
        private readonly SocialTokenKeyRotator $rotator,
        private readonly SocialTokenCipher $cipher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $etat = $this->rotator->status();

        $active = $this->cipher->currentVersion();
        $connues = $this->cipher->knownVersions();

        $io->writeln(sprintf('Cle active : v%d. Cles dechiffrables : %s.', $active, implode(', ', array_map(
            static fn (int $v): string => 'v' . $v,
            $connues,
        ))));

        if ($etat['total'] === 0) {
            $io->success('Aucun jeton stocke.');

            return Command::SUCCESS;
        }

        $lignes = [];
        foreach ($etat['byVersion'] as $version => $nombre) {
            $lignes[] = [
                'v' . $version,
                $nombre,
                $version === $active ? 'active' : (\in_array($version, $connues, true) ? 'retiree (lisible)' : 'CLE ABSENTE'),
            ];
        }
        $io->table(['Version', 'Jetons', 'Etat'], $lignes);

        if ($etat['unreadable'] > 0) {
            $io->error(sprintf(
                '%d jeton(s) portent une version dont la cle n est plus declaree : ils sont illisibles. Remettez la cle, ou ces comptes devront etre reconnectes.',
                $etat['unreadable'],
            ));

            return Command::FAILURE;
        }

        $reste = $this->rotator->remaining();
        if ($reste > 0) {
            $io->note(sprintf('%d compte(s) pas encore a la cle active. Ne retirez aucune cle avant que ce nombre soit nul.', $reste));
        } else {
            $io->success('Tous les jetons sont a la cle active : les cles retirees peuvent etre supprimees de l environnement.');
        }

        return Command::SUCCESS;
    }
}
