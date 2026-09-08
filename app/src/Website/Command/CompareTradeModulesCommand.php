<?php

declare(strict_types=1);

namespace App\Website\Command;

use App\Fonctionnalite\Config\ActivityCapabilities;
use App\Fonctionnalite\Config\PresetVerticale;
use App\Fonctionnalite\Enum\Metier;
use App\Website\Entity\Trade;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `website:trades:modules-diff` — ce que les activités déduisent, face à ce que les préréglages donnent.
 *
 * ── ⚠ CE N'EST PAS UN GARDE-FOU, ET IL NE DOIT PAS LE DEVENIR ──────────────────────────────────
 *
 * Elle rend TOUJOURS 0. Un écart n'est pas forcément un défaut : il peut révéler que le préréglage a
 * dérivé, ce qui est déjà arrivé. Faire échouer une fusion sur un écart pousserait à faire coller le
 * calcul à la donnée existante — c'est-à-dire à effacer le signal au lieu de le lire.
 *
 * Le critère d'acceptation de la spec le dit : « le critère est que l'écart soit CONNU ET JUSTIFIÉ,
 * pas qu'il soit nul ».
 *
 * ── ⚠ TROIS SOURCES DÉCRIVENT CE QU'UN MÉTIER « ALLUME », ET ELLES NE PARLENT PAS DE LA MÊME CHOSE
 *
 *   - `PresetVerticale` liste des CAPACITÉS activées à l'ouverture d'une structure ;
 *   - `specs/verticales/composition.md` liste des ACTIVITÉS au sens de D15 ;
 *   - l'écran « Ouvrir une structure » affiche une PHRASE recopiée à la main.
 *
 * Comparer les deux premières, c'est comparer une conséquence à une cause. Cette commande ne dit
 * donc pas qui a raison : elle rend l'écart lisible pour qu'un humain tranche, ligne par ligne.
 */
#[AsCommand(
    name: 'website:trades:modules-diff',
    description: 'Compare les modules déduits des activités d’un métier à ceux de son préréglage.',
)]
final class CompareTradeModulesCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<Trade> $lignes */
        $lignes = $this->em->getRepository(Trade::class)->findBy([], ['position' => 'ASC']);

        if ([] === $lignes) {
            /*
             * ⚠ ZÉRO LIGNE N'EST PAS « AUCUN ÉCART ». C'est « rien à comparer ». Rendre un tableau
             *   vide ici laisserait croire que tout concorde, alors que la mesure n'a pas eu lieu —
             *   le mensonge du zéro, exactement.
             */
            $io->warning('Aucun métier en base : rien à comparer. Lancez d’abord « website:trades:seed ».');

            return Command::SUCCESS;
        }

        $rangs = [];
        $totalEcarts = 0;
        $compares = 0;

        foreach ($lignes as $ligne) {
            $metier = Metier::tryFrom($ligne->getCode());

            if (null === $metier) {
                /*
                 * Un métier créé en base n'a pas de préréglage : il n'y a rien à comparer, et ce
                 * n'est pas un défaut — c'est précisément le cas que le référentiel rend possible.
                 */
                $rangs[] = [$ligne->getCode(), '—', '—', 'créé en base, aucun préréglage'];

                continue;
            }

            ++$compares;

            $activites = [];
            foreach ($ligne->getActivities() as $activite) {
                $activites[] = $activite->getActivity();
            }

            $deduit = ActivityCapabilities::modulesFor($activites);
            $prereglé = PresetVerticale::capacites($metier);

            sort($deduit);
            sort($prereglé);

            $enPlus = array_values(array_diff($deduit, $prereglé));
            $enMoins = array_values(array_diff($prereglé, $deduit));

            $totalEcarts += \count($enPlus) + \count($enMoins);

            $rangs[] = [
                $ligne->getCode(),
                [] === $enPlus ? '—' : implode(', ', $enPlus),
                [] === $enMoins ? '—' : implode(', ', $enMoins),
                sprintf('%d déduits / %d préréglés', \count($deduit), \count($prereglé)),
            ];
        }

        $io->table(
            ['métier', 'déduit, absent du préréglage', 'préréglé, non déduit', 'volumes'],
            $rangs,
        );

        /*
         * ⚠ LE TÉMOIN POSITIF. Sans lui, une boucle qui n'aurait comparé personne rendrait « 0
         *   écart » — un vert obtenu en ne mesurant rien. Le nombre de métiers RÉELLEMENT comparés
         *   doit être lisible à côté du nombre d'écarts.
         */
        $io->writeln(sprintf(
            ' <info>%d métier(s) comparé(s)</info>, <comment>%d écart(s)</comment> au total.',
            $compares,
            $totalEcarts,
        ));

        $io->note(
            'Un écart n’est pas un défaut : il peut révéler que le préréglage a dérivé. '
            .'Cette commande rend toujours 0 — elle informe, elle n’arbitre pas.',
        );

        return Command::SUCCESS;
    }
}
