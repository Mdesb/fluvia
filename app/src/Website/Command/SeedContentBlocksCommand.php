<?php

declare(strict_types=1);

namespace App\Website\Command;

use App\Website\Entity\ContentBlock;
use App\Website\Service\HomeBlocks;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `website:blocks:seed` — écrit en base le contenu initial de la page d'accueil (ED-10).
 *
 * **Pourquoi une commande et pas une migration.** Une migration de données recopierait ce texte dans
 * un fichier daté que personne ne relit, et le jour où l'on ajoute un bloc au gabarit, il faudrait
 * une seconde migration. Ici, ajouter un bloc à {@see HomeBlocks} et relancer la commande suffit.
 *
 * ⚠ **ELLE NE TOUCHE JAMAIS UN BLOC DÉJÀ RENSEIGNÉ.** C'est la seule propriété qui compte : sans
 * elle, un déploiement écraserait le texte que quelqu'un vient d'écrire par celui qui dort dans le
 * code — et le rédacteur verrait sa page revenir en arrière sans comprendre pourquoi. Elle est donc
 * rejouable autant qu'on veut, et `--force` existe pour le cas explicite où l'on VEUT revenir au
 * texte d'origine.
 */
#[AsCommand(
    name: 'website:blocks:seed',
    description: 'Écrit le contenu initial des blocs de la page d’accueil, sans jamais écraser un bloc rempli (ED-10).',
)]
final class SeedContentBlocksCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Réécrit AUSSI les blocs déjà remplis, avec le texte d’origine. Geste explicite : il efface ce qui a été rédigé.')
            ->addOption('a-blanc', null, InputOption::VALUE_NONE, 'Montre ce qui serait écrit sans rien enregistrer.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');
        $aBlanc = (bool) $input->getOption('a-blanc');
        $maintenant = new \DateTimeImmutable();

        $poses = 0;
        $gardes = 0;

        foreach (HomeBlocks::all() as $declare) {
            $existant = $this->em->getRepository(ContentBlock::class)->find($declare['key']);

            if (null !== $existant && !$force) {
                ++$gardes;
                continue;
            }

            if ($aBlanc) {
                $io->text(sprintf('  écrirait %s (%s)', $declare['key'], $declare['label']));
                ++$poses;
                continue;
            }

            $bloc = $existant ?? new ContentBlock($declare['key']);
            $bloc->setValue($declare['initialValue'], $maintenant);
            $this->em->persist($bloc);
            ++$poses;
        }

        if (!$aBlanc) {
            $this->em->flush();
        }

        $io->success(sprintf(
            '%s : %d bloc(s) écrit(s), %d conservé(s) tels quels.',
            $aBlanc ? 'À blanc' : 'Blocs de la page d’accueil',
            $poses,
            $gardes,
        ));

        return Command::SUCCESS;
    }
}
