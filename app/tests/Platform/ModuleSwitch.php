<?php

declare(strict_types=1);

namespace App\Tests\Platform;

use App\Fonctionnalite\Service\Fonctionnalites;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Allume ou éteint la capacité d'un module pour un test (recentrage, CA-5).
 *
 * Depuis la garde serveur des modules, un module à capacité éteint rend 404 sur toutes ses
 * opérations. Un test qui exerce un module doit donc l'allumer EXPLICITEMENT dans sa préparation :
 * l'état « allumé » n'est plus un défaut implicite, c'est une précondition que le test pose.
 *
 * Passe par `Fonctionnalites::definir()`, le seul chemin d'écriture de la production : un test qui
 * écrirait la ligne à la main testerait un état que l'application ne sait pas produire.
 */
trait ModuleSwitch
{
    /**
     * @param string ...$establishmentNames noms d'établissement ; aucun = tous les établissements
     */
    protected function switchModule(string $capability, bool $on, string ...$establishmentNames): void
    {
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();
        /** @var Fonctionnalites $features */
        $features = $container->get(Fonctionnalites::class);

        $repository = $em->getRepository(Etablissement::class);
        $establishments = $establishmentNames === []
            ? $repository->findAll()
            : array_map(static fn (string $name): ?Etablissement => $repository->findOneBy(['nom' => $name]), $establishmentNames);

        foreach ($establishments as $establishment) {
            if (!$establishment instanceof Etablissement) {
                throw new \LogicException('Établissement introuvable : le montage du test est faux.');
            }

            $features->definir($establishment, $capability, $on, null);
        }
    }
}
