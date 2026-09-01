<?php

declare(strict_types=1);

namespace App\Platform\Command;

use Doctrine\Bundle\FixturesBundle\Loader\SymfonyFixturesLoader;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Purger\ORMPurgerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Recharge les données de démonstration **sans rien détruire** (T6).
 *
 * **Le besoin.** La démonstration de la préproduction dérive : elle est modifiée au fil des essais et
 * rien ne permet de la remettre d'aplomb. Les 37 classes de démonstration décrivent pourtant l'état
 * voulu — elles n'étaient simplement pas atteignables là-bas, `DoctrineFixturesBundle` étant limité à
 * `dev` et `test`.
 *
 * **Pourquoi une commande à nous plutôt que `doctrine:fixtures:load --append`.** Les deux font la même
 * chose. Mais la commande du bundle fait *par défaut* l'inverse de ce qu'on veut ici : elle **purge**.
 * Un jour de fatigue, `--append` s'oublie, et l'incident du 24/08 se rejoue à l'identique. Un nom qui
 * dit ce qu'il fait — « charger », pas « load » avec un drapeau qui décide de tout — retire cette
 * possibilité au lieu de la documenter. Le garde
 * {@see \App\Platform\DataFixtures\PurgeurInterditHorsDeveloppement} ferme l'autre moitié du risque.
 *
 * **Ce que « sans rien détruire » veut dire exactement** : les fixtures cherchent avant de créer (voir
 * `FixturesIdempotentes`). Recharger n'écrase donc pas ce qui existe et n'en fait pas de doublons —
 * mesuré sur les 329 tables du schéma, deux chargements successifs laissent des comptes identiques.
 * Ce qu'un exploitant a modifié à la main sur un objet de démonstration **reste modifié** : cette
 * commande complète, elle ne réinitialise pas. Remettre à zéro reste un `doctrine:fixtures:load` en
 * `dev` sur une base jetable.
 */
#[AsCommand(
    name: 'app:demo:charger',
    description: 'Charge les données de démonstration manquantes, sans purger la base.',
)]
final class ChargerDemonstrationCommand extends Command
{
    public function __construct(
        private readonly SymfonyFixturesLoader $chargeur,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $fixtures = $this->chargeur->getFixtures();
        if ($fixtures === []) {
            $io->error('Aucune classe de démonstration trouvée.');

            return Command::FAILURE;
        }

        $io->text(sprintf('%d classe(s) de démonstration à passer en revue.', \count($fixtures)));

        // Purgeur inerte **et** mode additif : deux verrous pour un seul risque, parce que celui-ci a
        // déjà coûté une préproduction. L'exécuteur exige un purgeur même quand il ne s'en sert pas.
        $executeur = new ORMExecutor($this->em, new class implements ORMPurgerInterface {
            public function setEntityManager(EntityManagerInterface $em): void
            {
            }

            public function purge(): void
            {
            }
        });
        $executeur->execute($fixtures, append: true);

        $io->success('Démonstration à jour. Rien n\'a été supprimé.');

        return Command::SUCCESS;
    }
}
