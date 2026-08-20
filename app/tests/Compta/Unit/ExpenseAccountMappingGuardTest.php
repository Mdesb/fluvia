<?php

declare(strict_types=1);

namespace App\Tests\Compta\Unit;

use App\Compta\DataFixtures\ComptaFixtures;
use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\ExpenseAccountMapping;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\Compta\Service\ExpenseAccountMappingGuard;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * CA-3 (RG-M6-12) : une nature de charge sans `ExpenseAccountMapping` actif **bloque** la génération
 * (anomalie remontée) sans jamais lever d'exception — dégradation propre, même patron que
 * `MappingComptableGuardTest`.
 */
final class ExpenseAccountMappingGuardTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ExpenseAccountMappingGuard $guard;
    private ProfilExploitant $profil;
    private CompteComptable $compte;
    private TauxTva $taux;

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

        // Test unitaire : on instancie la garde directement avec l'EntityManager réel (sa seule
        // dépendance) plutôt que de la tirer du conteneur — robuste quel que soit l'état du cache DI
        // ou la publicité du service (qui n'est référencé qu'à partir de FIN-2/FIN-3).
        $this->guard = new ExpenseAccountMappingGuard($this->em);
        $this->profil = $em->getRepository(ProfilExploitant::class)->findOneBy(['siren' => ComptaFixtures::PROFIL_SIREN]);
        $this->compte = $em->getRepository(CompteComptable::class)->findOneBy(['profilExploitant' => $this->profil->getId(), 'numero' => '627000']);
        $this->taux = $em->getRepository(TauxTva::class)->findOneBy(['profilExploitant' => $this->profil->getId(), 'taux' => '20.00']);
    }

    public function testMappingIncompletBloqueSansBloquerLaSaisieAppelante(): void
    {
        // Aucun `ExpenseAccountMapping` n'existe pour 'travel' -> anomalie remontée, aucune exception.
        $anomalies = $this->guard->anomalies($this->profil, 'travel');

        self::assertNotEmpty($anomalies, 'Mapping absent : au moins une anomalie doit être remontée (CA-3).');
        self::assertNull($this->guard->resoudre($this->profil, 'travel'));
    }

    public function testMappingInactifBloqueAussi(): void
    {
        $mapping = (new ExpenseAccountMapping())
            ->setBusinessProfile($this->profil)
            ->setExpenseNatureCode('lodging')
            ->setExpenseAccount($this->compte)
            ->setDeductibleVatRate($this->taux)
            ->setActive(false);
        $this->em->persist($mapping);
        $this->em->flush();

        $anomalies = $this->guard->anomalies($this->profil, 'lodging');
        self::assertNotEmpty($anomalies);
        self::assertNull($this->guard->resoudre($this->profil, 'lodging'));
    }

    public function testMappingCompletEtActifNeRemonteAucuneAnomalie(): void
    {
        $mapping = (new ExpenseAccountMapping())
            ->setBusinessProfile($this->profil)
            ->setExpenseNatureCode('default_supplier')
            ->setExpenseAccount($this->compte)
            ->setDeductibleVatRate($this->taux)
            ->setActive(true);
        $this->em->persist($mapping);
        $this->em->flush();

        self::assertSame([], $this->guard->anomalies($this->profil, 'default_supplier'));
        self::assertNotNull($this->guard->resoudre($this->profil, 'default_supplier'));
    }
}
