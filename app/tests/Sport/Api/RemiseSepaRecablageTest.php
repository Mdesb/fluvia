<?php

declare(strict_types=1);

namespace App\Tests\Sport\Api;

use App\Sepa\Entity\ConfigCreancierSepa;
use App\Sepa\Enum\StatutRemiseSepa;
use App\Sport\Entity\EcheanceSepa;
use App\Sport\Enum\StatutEcheanceSepa;
use App\Sport\Service\GenererRemiseSepaHandler;
use App\Tests\Sport\SportApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Preuve du recâblage Sport → module SEPA partagé (plan-sepa.md §5) : `GenererRemiseSepaHandler`
 * (Sport) délègue entièrement à `App\Sepa\Service\GenerationRemiseHandler` via le port
 * `SportEcheanceSepaSource` — la remise produite est une **vraie** `App\Sepa\Entity\RemiseSepa` avec
 * un pain.008 réel, l'échéancier fitness Sport est mis à jour (`marquerCollectees`).
 */
final class RemiseSepaRecablageTest extends SportApiTestCase
{
    public function testGenererRemiseProduitUnPain008ReelEtMarqueLesEcheancesCollectees(): void
    {
        [, , $idA] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etablissement = $em->getRepository(\App\Organisation\Entity\Etablissement::class)->find($idA);
        self::assertNotNull($etablissement);

        $configCreancier = $em->getRepository(ConfigCreancierSepa::class)->findOneBy(['etablissement' => $etablissement]);
        self::assertNotNull($configCreancier, 'Fixture ComptaFixtures/SepaFixtures : config créancier régie requise sur A.');
        self::assertSame('regie', $configCreancier->getVariante()?->value);

        $dateExecution = new \DateTimeImmutable('today');
        $nbEcheancesDuesAvant = \count($em->getRepository(EcheanceSepa::class)->createQueryBuilder('e')
            ->join('e.abonnement', 'a')
            ->andWhere('IDENTITY(a.etablissement) = :etab')
            ->andWhere('e.statut = :av')
            ->andWhere('e.dateProgrammee <= :date')
            ->setParameter('etab', $etablissement->getId(), 'uuid')
            ->setParameter('av', StatutEcheanceSepa::AVenir->value)
            ->setParameter('date', $dateExecution, 'date_immutable')
            ->getQuery()->getResult());
        self::assertGreaterThan(0, $nbEcheancesDuesAvant, 'Le jeu de démonstration Sport doit avoir au moins une échéance due.');

        /** @var GenererRemiseSepaHandler $handler */
        $handler = static::getContainer()->get(GenererRemiseSepaHandler::class);
        $remise = $handler->generer($etablissement, $dateExecution);

        self::assertSame(StatutRemiseSepa::Transmise, $remise->getStatut());
        self::assertSame($nbEcheancesDuesAvant, $remise->getNbTxs());
        self::assertGreaterThan(0, $remise->getCtrlSumCentimes());
        self::assertNotNull($remise->getContenuXml());
        self::assertStringContainsString('urn:iso:std:iso:20022:tech:xsd:pain.008.001.02', (string) $remise->getContenuXml());
        // Établissement A = régie (ProfilExploitant régie directe, ComptaFixtures) : UltmtCdtr attendu.
        self::assertStringContainsString('<UltmtCdtr>', (string) $remise->getContenuXml());

        $em->clear();
        $nbPrelevees = \count($em->getRepository(EcheanceSepa::class)->createQueryBuilder('e')
            ->join('e.abonnement', 'a')
            ->andWhere('IDENTITY(a.etablissement) = :etab')
            ->andWhere('e.statut = :prelevee')
            ->setParameter('etab', $etablissement->getId(), 'uuid')
            ->setParameter('prelevee', StatutEcheanceSepa::Prelevee->value)
            ->getQuery()->getResult());
        self::assertSame($nbEcheancesDuesAvant, $nbPrelevees, 'EcheanceSepaSource::marquerCollectees a bien mis à jour l\'échéancier Sport.');
    }
}
