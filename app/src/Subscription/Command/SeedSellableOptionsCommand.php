<?php

declare(strict_types=1);

namespace App\Subscription\Command;

use App\Fonctionnalite\Service\CatalogueCapacites;
use App\Subscription\Entity\PlanOption;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * CREE LES OPTIONS VENDABLES MANQUANTES, A UN PRIX PROVISOIRE.
 *
 * Demande de Maxime le 01/09 : « pose des montants de depart, je corrigerai ». Sans option
 * tarifee, l'onglet Modules affiche un catalogue et rien a acheter — il montre ce qui existe et ne
 * peut rien vendre.
 *
 * ── CE QU'ELLE NE CREE PAS, ET C'EST LE POINT ───────────────────────────────────────────────────
 *
 * ⚠ Les **verticales d'activite** — piscine, sport, padel, patinoire, musee — sont exclues.
 * « Padel, ce n'est pas un module » : ce sont des valeurs de l'enum `Metier`, des presets qui
 * activent chacun un jeu de capacites. Ce qu'un etablissement EST, pas ce qu'il ajoute a la carte.
 * L'exclusion se lit sur `DescripteurCapacite::estVerticale`, derive de l'enum — pas recopiee ici,
 * sans quoi les deux listes divergeraient au premier ajout.
 *
 * ── POURQUOI UN PRIX UNIFORME, ET DELIBEREMENT VISIBLE COMME PROVISOIRE ────────────────────────
 *
 * Un bareme differencie — moins cher pour une capacite transverse, plus cher pour un module metier —
 * aurait l'air d'une decision commerciale. Ce n'en est pas une : personne ne l'a prise. Vingt fois
 * le meme montant se lit immediatement comme un remplissage, ce qui est exactement ce que c'est, et
 * appelle la correction au lieu de s'installer.
 *
 * ── IDEMPOTENTE, ET ELLE NE TOUCHE JAMAIS UN PRIX EXISTANT ─────────────────────────────────────
 *
 * ⚠ Elle ne cree que ce qui MANQUE. Un prix deja saisi — par Maxime dans `/editeur`, ou par une
 * execution precedente — n'est jamais ecrase. Une commande de remplissage qui reecrit ce qu'un
 * humain a corrige est pire que pas de commande du tout : elle defait un travail sans le dire, et
 * on ne s'en apercoit qu'a la facture.
 */
#[AsCommand(
    name: 'subscription:options:seed',
    description: "Cree les options vendables manquantes a un prix provisoire (n'ecrase aucun prix existant).",
)]
final class SeedSellableOptionsCommand extends Command
{
    /** Provisoire, uniforme, et visible comme tel. 19,00 € par mois. */
    private const PRIX_PROVISOIRE_CENTIMES = 1900;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CatalogueCapacites $catalogue,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Dit ce qui serait cree, sans rien ecrire.')
            ->addOption('prix', null, InputOption::VALUE_REQUIRED, 'Prix mensuel en centimes (defaut : 1900).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simulation = (bool) $input->getOption('dry-run');
        $prix = (int) ($input->getOption('prix') ?? self::PRIX_PROVISOIRE_CENTIMES);

        if ($prix <= 0) {
            $io->error('Un prix nul ou negatif afficherait « gratuit » dans la boutique. Refuse.');

            return Command::FAILURE;
        }

        $existantes = [];
        /** @var array<string, PlanOption> $objets */
        $objets = [];
        foreach ($this->em->getRepository(PlanOption::class)->findAll() as $option) {
            $existantes[$option->getCapability()] = $option->getMonthlyPriceCents();
            $objets[$option->getCapability()] = $option;
        }

        $crees = [];
        $ignorees = [];
        $verticales = [];
        $nonServables = [];
        $desactivees = [];

        foreach ($this->catalogue->toutes() as $descripteur) {
            if ($descripteur->estVerticale) {
                $verticales[] = $descripteur->code;
                continue;
            }

            // ⚠ UN MODULE QUI NE PEUT RIEN SERVIR NE SE MET PAS EN VENTE (§8.1, arbitrage du 04/09).
            //
            // Et s'il est DÉJÀ en vente, on l'en retire : trois options à 19,00 € / mois existaient
            // pour `lodging`, `stay` et `dining` au moment de l'arbitrage. Une commande qui se
            // contenterait de ne plus en créer laisserait exactement ce qu'elle est censée empêcher.
            //
            // ⚠ ELLE NE SUPPRIME PAS, ELLE DÉSACTIVE. Le prix saisi survit, et le jour où le module
            //   sert, une seule case le remet en vente — là où une suppression demanderait de
            //   retrouver ce que quelqu'un avait décidé.
            if (!$descripteur->peutServir) {
                $nonServables[] = $descripteur->code;

                $option = $objets[$descripteur->code] ?? null;
                if (null !== $option && $option->isActive()) {
                    if (!$simulation) {
                        $option->setActive(false);
                    }
                    $desactivees[] = $descripteur->code;
                }

                continue;
            }

            if (\array_key_exists($descripteur->code, $existantes)) {
                $ignorees[] = $descripteur->code;
                continue;
            }

            if (!$simulation) {
                $option = new PlanOption();
                $option->setCapability($descripteur->code)
                    ->setLabel($descripteur->libelle)
                    ->setMonthlyPriceCents($prix)
                    ->setActive(true);
                $this->em->persist($option);
            }

            $crees[] = $descripteur->code;
        }

        if (!$simulation && ($crees !== [] || $desactivees !== [])) {
            $this->em->flush();
        }

        $io->writeln(sprintf(
            '%s %d option(s) a %s € / mois',
            $simulation ? 'SERAIENT creees :' : 'Creees :',
            \count($crees),
            number_format($prix / 100, 2, ',', ' '),
        ));
        foreach ($crees as $code) {
            $io->writeln('    + ' . $code);
        }

        // ⚠ ON NOMME CE QU'ON N'A PAS TOUCHE. Une commande qui ne dit que ce qu'elle a fait laisse
        // croire qu'elle a tout traite — et personne ne saurait qu'un prix existant a ete respecte.
        // ⚠ ON NOMME CE QU'ON A RETIRÉ DE LA VENTE, ET POURQUOI. Une option qui disparaît de la
        //   boutique sans que rien ne le dise se lit comme une panne, et quelqu'un la « répare ».
        if ($nonServables !== []) {
            $io->writeln('');
            $io->writeln(sprintf(
                '%d module(s) NON MIS EN VENTE — ils ne peuvent rien servir (§8.1) :',
                \count($nonServables),
            ));
            foreach ($nonServables as $code) {
                $io->writeln(sprintf(
                    '    · %-24s %s',
                    $code,
                    \in_array($code, $desactivees, true)
                        ? ($simulation ? 'SERAIT désactivé (il était en vente)' : 'DÉSACTIVÉ (il était en vente)')
                        : 'déjà hors vente',
                ));
            }
        }

        if ($ignorees !== []) {
            $io->writeln('');
            $io->writeln(sprintf('%d option(s) deja tarifee(s), prix INCHANGE :', \count($ignorees)));
            foreach ($ignorees as $code) {
                $io->writeln(sprintf('    = %-24s %s €', $code, number_format($existantes[$code] / 100, 2, ',', ' ')));
            }
        }

        $io->writeln('');
        $io->writeln(sprintf(
            '%d verticale(s) d\'activite exclue(s) — ce n\'est pas un module : %s',
            \count($verticales),
            implode(', ', $verticales),
        ));

        if ($simulation) {
            $io->note('Simulation : rien n\'a ete ecrit.');
        } elseif ($crees !== []) {
            $io->warning(sprintf(
                'Ces %d prix sont PROVISOIRES et identiques : ils remplissent la boutique, ils ne '
                . 'la tarifent pas. A corriger dans /editeur -> Offres.',
                \count($crees),
            ));
        }

        return Command::SUCCESS;
    }
}
