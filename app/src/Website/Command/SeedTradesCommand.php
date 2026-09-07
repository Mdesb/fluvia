<?php

declare(strict_types=1);

namespace App\Website\Command;

use App\Fonctionnalite\Enum\EstablishmentActivity;
use App\Website\Config\TradeFallback;
use App\Website\Entity\Trade;
use App\Website\Entity\TradeActivity;
use App\Website\Enum\PublicationStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `website:trades:seed` — matérialise en base les cinq métiers du repli.
 *
 * ── POURQUOI UNE COMMANDE ET PAS UNE MIGRATION ─────────────────────────────────────────────────
 *
 * D66-ter : « **Une migration ne fabrique jamais de donnée métier. Ce qui manque reste visiblement
 * manquant.** » Une migration qui sèmerait ces lignes les recréerait sur chaque environnement neuf,
 * y compris celles qu'un exploitant aurait délibérément retirées — et il les resupprimerait à chaque
 * version, sans jamais comprendre d'où elles reviennent.
 *
 * ── ⚠ ELLE NE TOUCHE JAMAIS UNE LIGNE EXISTANTE ────────────────────────────────────────────────
 *
 * C'est la seule propriété qui compte, et c'est celle de {@see SeedContentBlocksCommand}. Sans elle,
 * un déploiement écraserait le chapô que Maxime vient de corriger par celui qui dort dans le code,
 * et la page reviendrait en arrière sans explication. `--force` existe pour le cas explicite où l'on
 * VEUT revenir au texte d'origine — c'est la règle 2 de `specs/verticales/paquet.md` :
 * « `noupdate: true` est le défaut pour tout ce que le client peut modifier ».
 *
 * ⚠ **ET « LA LIGNE » INCLUT SES ACTIVITÉS.** Ni retirées, ni RECOMPLÉTÉES : une activité décochée
 * par un exploitant ne revient pas au déploiement suivant. C'est la moitié qui manquait — « ne
 * jamais retirer » ne protège rien tout seul, puisque c'est l'ajout qui ramène ce qu'on a retiré, et
 * ça changerait les modules suggérés sur sa page sans que personne l'ait demandé.
 *
 * ── ⚠ LE TOTAL IMPRIMÉ N'EST PAS DÉCORATIF ─────────────────────────────────────────────────────
 *
 * « 0 créée, 5 conservées » et « 0 créée, 0 conservée » se ressemblent dans un journal de
 * déploiement, et ne disent pas du tout la même chose : la première est un état sain, la seconde
 * veut dire que RIEN n'est en base et que le site sert le repli. Le total les sépare. C'est le
 * témoin positif qu'exige la règle du dépôt : un `[]` n'est pas une preuve d'absence.
 */
#[AsCommand(
    name: 'website:trades:seed',
    description: 'Matérialise les cinq métiers du référentiel, sans jamais écraser une ligne existante.',
)]
final class SeedTradesCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Réécrit AUSSI les métiers déjà en base, avec les textes d’origine. Geste explicite : il efface ce qui a été rédigé.')
            ->addOption('a-blanc', null, InputOption::VALUE_NONE, 'Montre ce qui serait écrit sans rien enregistrer.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');
        $aBlanc = (bool) $input->getOption('a-blanc');

        $depot = $this->em->getRepository(Trade::class);

        $crees = 0;
        $gardes = 0;
        $activitesAjoutees = 0;

        foreach (TradeFallback::entries() as $entree) {
            /*
             * ⚠ RÉCONCILIATION PAR `slug`, PAS PAR NOM. Réconcilier par libellé casse à la première
             *   traduction et crée un doublon à la première faute de frappe — c'est la règle 1 de
             *   `paquet.md`. Le `slug` vaut le `code` pour ces cinq entrées ; les deux clés ne
             *   peuvent donc pas se contredire au moment où cette commande s'exécute.
             */
            $ligne = $depot->findOneBy(['slug' => $entree['slug']]);

            if (null !== $ligne && !$force) {
                /*
                 * ⚠ ON NE COMPLETE PAS NON PLUS SES ACTIVITES, et c'est un defaut corrige apres
                 *   coup : cette branche appelait `completerLesActivites()`. Une activite qu'un
                 *   exploitant avait DECOCHEE revenait donc au deploiement suivant, et les modules
                 *   suggeres sur sa page changeaient sans que personne l'ait demande.
                 *
                 *   « Ne jamais retirer » ne suffit pas : c'est l'AJOUT qui ramene ce qu'on a
                 *   retire. Une ligne existante est de la donnee d'exploitation entiere — ses
                 *   textes ET sa composition. `--force` reste la porte de sortie explicite.
                 */
                ++$gardes;

                continue;
            }

            if (null === $ligne) {
                $ligne = new Trade();
                $ligne->setCode($entree['code'])->setSlug($entree['slug']);
                ++$crees;

                if (!$aBlanc) {
                    $this->em->persist($ligne);
                }
            } else {
                ++$gardes;
            }

            $ligne
                ->setName($entree['name'])
                ->setSearchTitle($entree['searchTitle'])
                ->setLead($entree['lead'])
                ->setPosition($entree['position'])
                ->setStatus(PublicationStatus::Published);

            $activitesAjoutees += $this->completerLesActivites($ligne, $entree['activities']);
        }

        /*
         * ⚠ À BLANC, IL SUFFIT DE NE PAS ENREGISTRER. Les objets modifiés en mémoire disparaissent
         *   avec le processus : ni `persist()`, ni `flush()`, donc rien n'atteint la base. Un
         *   `clear()` défensif serait pire que rien — il détacherait tout en plein milieu de la
         *   boucle, et les itérations suivantes travailleraient sur des objets orphelins.
         */
        if ($aBlanc) {
            $io->note('À blanc : rien n’a été enregistré.');
        } else {
            $this->em->flush();
        }

        $total = \count($depot->findAll());

        $io->success(sprintf(
            '%d métier(s) créé(s), %d conservé(s), %d activité(s) ajoutée(s). Total en base : %d.',
            $crees,
            $gardes,
            $activitesAjoutees,
            $total,
        ));

        /*
         * ⚠ UN TOTAL DE ZÉRO EST UN AVERTISSEMENT, PAS UN SUCCÈS DISCRET. Il veut dire que le site
         *   sert le repli et que rien n'a basculé — l'état exact qu'on ne verrait pas autrement,
         *   puisque les pages continuent de s'afficher normalement.
         */
        if (0 === $total && !$aBlanc) {
            $io->warning('Aucun métier en base : le site sert la liste de repli. Ce n’est pas une panne, mais rien n’a basculé.');
        }

        return Command::SUCCESS;
    }

    /**
     * Pose les activités d'une ligne QU'ON ÉCRIT — à la création, ou sous `--force`.
     *
     * ⚠ **ELLE N'EST JAMAIS APPELÉE SUR UNE LIGNE QU'ON CONSERVE**, et c'est le cœur de la garantie.
     * Elle l'était, et c'était un défaut : une activité décochée par un exploitant revenait au
     * déploiement suivant. « Ne jamais retirer » ne protège rien tout seul — c'est l'AJOUT qui
     * ramène ce que quelqu'un a retiré.
     *
     * Elle ne retire rien non plus : sous `--force`, on réécrit les textes d'origine, on ne remet
     * pas la composition à zéro.
     *
     * @param list<EstablishmentActivity> $attendues
     */
    private function completerLesActivites(Trade $ligne, array $attendues): int
    {
        $deja = [];

        foreach ($ligne->getActivities() as $existante) {
            $deja[$existante->getActivity()->value] = true;
        }

        $ajoutees = 0;
        $rang = $ligne->getActivities()->count() * 10;

        foreach ($attendues as $activite) {
            if (isset($deja[$activite->value])) {
                continue;
            }

            $rang += 10;
            $ligne->addActivity((new TradeActivity())->setActivity($activite)->setPosition($rang));
            ++$ajoutees;
        }

        return $ajoutees;
    }
}
