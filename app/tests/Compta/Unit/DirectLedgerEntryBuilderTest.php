<?php

declare(strict_types=1);

namespace App\Tests\Compta\Unit;

use App\Compta\DataFixtures\ComptaFixtures;
use App\Compta\Dto\DirectLedgerEntryLine;
use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\Journal;
use App\Compta\Entity\PeriodeComptable;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\Compta\Enum\StatutPeriode;
use App\Compta\Service\DirectLedgerEntryBuilder;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\UnitOfWork;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * §0.4 du plan : `DirectLedgerEntryBuilder` construit et scelle une écriture équilibrée mais ne
 * flush PAS — le flush reste sous la responsabilité de l'appelant.
 */
final class DirectLedgerEntryBuilderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DirectLedgerEntryBuilder $builder;
    private ProfilExploitant $profil;
    private Journal $journal;
    private CompteComptable $compteFournisseur;
    private CompteComptable $compteBanque;
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

        /** @var DirectLedgerEntryBuilder $builder */
        $builder = $container->get(DirectLedgerEntryBuilder::class);
        $this->builder = $builder;

        $this->profil = $em->getRepository(ProfilExploitant::class)->findOneBy(['siren' => ComptaFixtures::PROFIL_SIREN]);
        $this->journal = $em->getRepository(Journal::class)->findOneBy(['profilExploitant' => $this->profil->getId(), 'code' => 'OD']);
        $this->compteFournisseur = $em->getRepository(CompteComptable::class)->findOneBy(['profilExploitant' => $this->profil->getId(), 'numero' => '401000']);
        $this->compteBanque = $em->getRepository(CompteComptable::class)->findOneBy(['profilExploitant' => $this->profil->getId(), 'numero' => '512000']);
        $this->taux = $em->getRepository(TauxTva::class)->findOneBy(['profilExploitant' => $this->profil->getId(), 'libelle' => TauxTva::LIBELLE_HORS_CHAMP]);
    }

    public function testConstruitEtScelleSansFlush(): void
    {
        $periode = $this->periodeOuverte();
        $lignes = [
            new DirectLedgerEntryLine($this->compteBanque, debitCentimes: 1000, creditCentimes: 0, tauxTva: $this->taux),
            new DirectLedgerEntryLine($this->compteFournisseur, debitCentimes: 0, creditCentimes: 1000, tauxTva: $this->taux),
        ];

        $ecriture = $this->builder->construire($this->profil, $this->journal, $periode, new \DateTimeImmutable('2026-08-19'), 'OD test', $lignes);

        self::assertTrue($ecriture->estScellee(), 'L\'écriture retournée doit être scellée (empreinte non vide).');
        self::assertSame(UnitOfWork::STATE_MANAGED, $this->em->getUnitOfWork()->getEntityState($ecriture));

        // Pas de flush : aucune ligne visible via une requête SQL directe hors UoW.
        $nb = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM compta_ecriture_comptable');
        self::assertSame(0, $nb, 'Le builder ne doit jamais flush lui-même (§0.4 du plan).');
    }

    public function testPeriodeClotureeRefusee(): void
    {
        $periode = $this->periodeOuverte();
        $periode->setStatut(StatutPeriode::Cloturee);

        $lignes = [
            new DirectLedgerEntryLine($this->compteBanque, debitCentimes: 1000, creditCentimes: 0, tauxTva: $this->taux),
            new DirectLedgerEntryLine($this->compteFournisseur, debitCentimes: 0, creditCentimes: 1000, tauxTva: $this->taux),
        ];

        $this->expectException(ConflictHttpException::class);
        $this->builder->construire($this->profil, $this->journal, $periode, new \DateTimeImmutable('2026-08-19'), 'OD test', $lignes);
    }

    public function testDesequilibreRejeteMemeDefensifApresConstruction(): void
    {
        $periode = $this->periodeOuverte();
        $lignes = [
            new DirectLedgerEntryLine($this->compteBanque, debitCentimes: 1000, creditCentimes: 0, tauxTva: $this->taux),
            new DirectLedgerEntryLine($this->compteFournisseur, debitCentimes: 0, creditCentimes: 500, tauxTva: $this->taux),
        ];

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->builder->construire($this->profil, $this->journal, $periode, new \DateTimeImmutable('2026-08-19'), 'OD test', $lignes);
    }

    private function periodeOuverte(): PeriodeComptable
    {
        $periode = new PeriodeComptable();
        $periode->setProfilExploitant($this->profil);
        $periode->setDateDebut(new \DateTimeImmutable('2026-08-01'));
        $periode->setDateFin(new \DateTimeImmutable('2026-08-31'));
        $periode->setStatut(StatutPeriode::Ouverte);
        $this->em->persist($periode);
        $this->em->flush();

        return $periode;
    }
}
