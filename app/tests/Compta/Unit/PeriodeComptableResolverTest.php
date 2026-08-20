<?php

declare(strict_types=1);

namespace App\Tests\Compta\Unit;

use App\Compta\DataFixtures\ComptaFixtures;
use App\Compta\Entity\PeriodeComptable;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Enum\StatutPeriode;
use App\Compta\Service\PeriodeComptableResolver;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * §0.3 du plan : `PeriodeComptableResolver` factorise, sans y toucher, la logique de
 * `GenerateurEcrituresHandler::periodePour()`. `resoudre()` (lecture seule, utilisée par la saisie
 * manuelle) ne crée jamais de période ; `resoudreOuCreer()` reproduit le comportement historique.
 */
final class PeriodeComptableResolverTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PeriodeComptableResolver $resolver;
    private ProfilExploitant $profil;

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

        foreach ([SocleFixtures::class, OffreFixtures::class, ComptaFixtures::class] as $classe) {
            $container->get($classe)->load($em);
        }

        /** @var PeriodeComptableResolver $resolver */
        $resolver = $container->get(PeriodeComptableResolver::class);
        $this->resolver = $resolver;
        $this->profil = $em->getRepository(ProfilExploitant::class)->findOneBy(['siren' => ComptaFixtures::PROFIL_SIREN]);
    }

    public function testResoudreRenvoieNullSiAucunePeriodeExistante(): void
    {
        self::assertNull($this->resolver->resoudre($this->profil, new \DateTimeImmutable('2026-08-19')));
    }

    public function testResoudreTrouveLaPeriodeCouvrante(): void
    {
        $periode = new PeriodeComptable();
        $periode->setProfilExploitant($this->profil);
        $periode->setDateDebut(new \DateTimeImmutable('2026-08-01'));
        $periode->setDateFin(new \DateTimeImmutable('2026-08-31'));
        $periode->setStatut(StatutPeriode::Ouverte);
        $this->em->persist($periode);
        $this->em->flush();

        $trouvee = $this->resolver->resoudre($this->profil, new \DateTimeImmutable('2026-08-19'));
        self::assertNotNull($trouvee);
        self::assertTrue($periode->getId()->equals($trouvee->getId()));
    }

    public function testResoudreOuCreerCreeLaPeriodeManquante(): void
    {
        $nb = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM compta_periode_comptable');
        self::assertSame(0, $nb);

        $periode = $this->resolver->resoudreOuCreer($this->profil, new \DateTimeImmutable('2026-08-19'));
        self::assertTrue($periode->couvre(new \DateTimeImmutable('2026-08-19')));
        self::assertTrue($periode->estOuverte());
    }
}
