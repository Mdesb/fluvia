<?php

declare(strict_types=1);

namespace App\Tests\Dms\Unit;

use App\DataFixtures\SocleFixtures;
use App\Dms\DataFixtures\DmsFixtures;
use App\Dms\Entity\RetentionPolicy;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Idempotence de `DmsFixtures` (ordre A du 26/08).
 *
 * Permissions `dms.*`, nom du rôle « GED — Gestion des liens publics » et codes de `RetentionPolicy`
 * portent tous une unicité globale. Comme la fixture ne crée aucun utilisateur, elle doit être
 * **rejouable telle quelle** : on la charge deux fois et on vérifie qu'aucune ligne n'est dupliquée
 * (sinon le second `flush()` échouerait sur « Duplicate entry »).
 *
 * Le harnais ordinaire ne voit jamais ce cas : il recrée le schéma depuis les entités à chaque classe
 * de test, donc les fixtures partent toujours d'une base vide.
 */
final class DmsFixturesIdempotenceTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();
        $this->em = $em;

        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=1');

        // DmsFixtures dépend de SocleFixtures (rôle « Administrateur groupe »).
        $container->get(SocleFixtures::class)->load($em);
    }

    public function testDeuxChargementsSuccessifsNeDupliquentRien(): void
    {
        $fixture = static::getContainer()->get(DmsFixtures::class);

        $fixture->load($this->em);
        $this->em->clear();

        // Second chargement sur une base qui contient déjà tout : ne doit pas lever « Duplicate entry ».
        $fixture->load($this->em);

        self::assertCount(
            5,
            $this->em->getRepository(Permission::class)->findBy(['module' => 'dms']),
            'Les 5 permissions dms.* doivent rester uniques après deux chargements.',
        );
        self::assertCount(
            1,
            $this->em->getRepository(Role::class)->findBy(['nom' => DmsFixtures::ROLE_LIENS_PUBLICS]),
            'Le rôle « liens publics » doit rester unique après deux chargements.',
        );
        self::assertCount(
            1,
            $this->em->getRepository(RetentionPolicy::class)->findBy(['code' => DmsFixtures::POLICY_ACCOUNTING]),
            'La RetentionPolicy compta doit rester unique après deux chargements.',
        );
        self::assertCount(
            1,
            $this->em->getRepository(RetentionPolicy::class)->findBy(['code' => DmsFixtures::POLICY_HR]),
            'La RetentionPolicy RH doit rester unique après deux chargements.',
        );
    }
}
