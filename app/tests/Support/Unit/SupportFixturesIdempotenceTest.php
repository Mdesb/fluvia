<?php

declare(strict_types=1);

namespace App\Tests\Support\Unit;

use App\Tests\SchemaDuHarnais;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Support\DataFixtures\SupportFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Idempotence de `SupportFixtures` (ordre A du 26/08).
 *
 * `Role.nom` et `Permission(module, action)` portent une unicité globale. Charger la fixture sur une
 * base qui contient déjà certaines de ces lignes — la régénération des données de démo de la préprod —
 * échouait sur « Duplicate entry ». On pré-sème deux lignes que la fixture crée aussi, puis on charge :
 * si la garde `findOneBy`-avant-création fonctionne, le chargement passe et ne produit aucun doublon ;
 * sinon la contrainte d'unicité fait échouer le `flush()`.
 *
 * Le harnais ordinaire ne voit jamais ce cas : il recrée le schéma depuis les entités à chaque classe
 * de test, donc les fixtures partent toujours d'une base vide.
 */
final class SupportFixturesIdempotenceTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();
        $this->em = $em;

        // Le schéma est construit UNE FOIS par processus, puis vidé entre les tests. Le faire
        // détruire et reconstruire par chaque `setUp()` coûtait ~10 s par test — six heures sur
        // la suite complète, et donc une suite que personne ne lançait.
        SchemaDuHarnais::reinitialiser($em);
    }

    public function testChargementSurUneBaseQuiContientDejaDesLignesSupportNeDuplique(): void
    {
        // Base pré-existante : une permission et un rôle que la fixture crée également.
        $this->em->persist((new Permission())->setModule('support')->setAction('lire'));
        $this->em->persist((new Role())->setNom('Support Administrateur'));
        $this->em->flush();
        $this->em->clear();

        // Ne doit pas lever « Duplicate entry » : la fixture réutilise l'existant.
        static::getContainer()->get(SupportFixtures::class)->load($this->em);

        self::assertCount(
            1,
            $this->em->getRepository(Permission::class)->findBy(['module' => 'support', 'action' => 'lire']),
            'La permission support.lire pré-existante doit être réutilisée, pas dupliquée.',
        );
        self::assertCount(
            1,
            $this->em->getRepository(Role::class)->findBy(['nom' => 'Support Administrateur']),
            'Le rôle « Support Administrateur » pré-existant doit être réutilisé, pas dupliqué.',
        );

        // Toutes les permissions du module sont bien présentes (la garde n'a rien perdu).
        self::assertCount(
            \count(SupportFixtures::ACTIONS),
            $this->em->getRepository(Permission::class)->findBy(['module' => 'support']),
        );
    }
}
