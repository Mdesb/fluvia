<?php

declare(strict_types=1);

namespace App\Tests\Piscine\Unit;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Espace;
use App\Organisation\Entity\Etablissement;
use App\Piscine\Entity\Bassin;
use App\Piscine\Entity\CreneauBassin;
use App\Piscine\Entity\LigneEau;
use App\Piscine\Entity\ParametrePiscineEtablissement;
use App\Piscine\Enum\EtatLigneEau;
use App\Piscine\Service\PossProrataCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Prorata de la jauge grand public (US-L6-07, CA-7) : formule par défaut (mode `lignes`)
 * `capaciteRestante = round(capacite × (1 − lignesReservees / lignesTotales))`.
 */
final class PossProrataCalculatorTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PossProrataCalculator $calculateur;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get('doctrine')->getManager();
        $this->calculateur = $container->get(PossProrataCalculator::class);

        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        /** @var \App\DataFixtures\SocleFixtures $socle */
        $socle = $container->get(SocleFixtures::class);
        $socle->load($this->em);
    }

    public function testCa7CapaciteRestanteDiminueAuProrataDesLignesReservees(): void
    {
        [$bassin, $lignes, $creneau] = $this->creerBassinQuatreLignes(capacite: 60);

        // Aucune ligne réservée : capacité restante = capacité pleine.
        $jauge = $this->calculateur->recalculer($creneau);
        self::assertSame(60, $jauge->getCapaciteRestante());

        // 1 ligne sur 4 réservée (club) : capacité restante = round(60 × (1 − 1/4)) = 45.
        $lignes[0]->setEtat(EtatLigneEau::Reservee);
        $this->em->flush();
        $jauge = $this->calculateur->recalculer($creneau);
        self::assertSame(45, $jauge->getCapaciteRestante());

        // 2 lignes sur 4 réservées : capacité restante = round(60 × (1 − 2/4)) = 30.
        $lignes[1]->setEtat(EtatLigneEau::Reservee);
        $this->em->flush();
        $jauge = $this->calculateur->recalculer($creneau);
        self::assertSame(30, $jauge->getCapaciteRestante());

        // Libération d'une ligne : la jauge grand public remonte immédiatement (US-L6-07, temps réel).
        $lignes[0]->setEtat(EtatLigneEau::Publique);
        $this->em->flush();
        $jauge = $this->calculateur->recalculer($creneau);
        self::assertSame(45, $jauge->getCapaciteRestante());
    }

    /** @return array{0: Bassin, 1: list<LigneEau>, 2: CreneauBassin} */
    private function creerBassinQuatreLignes(int $capacite): array
    {
        $etab = $this->em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertNotNull($etab);

        $espace = (new Espace())->setNom('Bassin prorata test')->setEtablissement($etab)->setType('bassin');
        $this->em->persist($espace);

        $bassin = (new Bassin())->setLibelle('Bassin prorata test')->setEspace($espace)->setNbLignes(4)->setCapacite($capacite);
        $this->em->persist($bassin);

        $this->em->persist((new ParametrePiscineEtablissement())->setEtablissement($etab));

        $lignes = [];
        for ($numero = 1; $numero <= 4; ++$numero) {
            $ligne = (new LigneEau())->setNumero($numero)->setBassin($bassin);
            $this->em->persist($ligne);
            $lignes[] = $ligne;
        }

        $creneau = (new CreneauBassin())->setBassin($bassin)
            ->setDebut(new \DateTimeImmutable('2026-09-01 09:00:00'))
            ->setFin(new \DateTimeImmutable('2026-09-01 10:00:00'));
        $this->em->persist($creneau);

        $this->em->flush();

        return [$bassin, $lignes, $creneau];
    }
}
