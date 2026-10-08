<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\Command\RealignCardDeadlinesCommand;
use App\Acces\Enum\TypeDroitAcces;
use App\Audit\Entity\EntreeAudit;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Produit;
use App\Organisation\Entity\Etablissement;
use App\Tests\Acces\AccesApiTestCase;
use App\Tests\Acces\SnapshotDeltaTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Reprise de #297 : les droits de carte émis avant le correctif finissent à 00:00 UTC le jour butoir
 * (refusés dès 01:00 à Paris). La commande les recale à 23:59:59, heure de l'établissement, et la
 * borne synchronisée en delta reçoit la nouvelle fin (décision de Maxime du 08/10/2026).
 */
final class RealignCardDeadlinesCommandTest extends AccesApiTestCase
{
    use SnapshotDeltaTrait;

    /** @return iterable<string, array{string, string}> */
    public static function fuseaux(): iterable
    {
        yield 'Paris (UTC+1 le 31/01)' => ['Europe/Paris', '2027-01-31T22:59:59+00:00'];
        yield 'Martinique (UTC-4)' => ['America/Martinique', '2027-02-01T03:59:59+00:00'];
    }

    #[DataProvider('fuseaux')]
    public function testOldCardDeadlinesAreRealignedOnceAndReachTheOfflineTerminal(string $fuseau, string $attendue): void
    {
        $em = $this->snapshotEm();
        foreach ($em->getRepository(Etablissement::class)->findAll() as $etablissement) {
            $etablissement->setFuseauHoraire($fuseau);
        }
        $produit = $this->entite(Produit::class, ['libelleRecherche' => OffreFixtures::PRODUIT_CARTE]);
        $produit->getCarte()?->setValiditeDuree(null)->setDateButoir(new \DateTimeImmutable('2027-01-31'));
        $em->flush();

        // L'ancienne règle : la date butoir à 00:00 UTC. Témoins : minuit UTC d'un autre jour, et un
        // billet (pas une carte) à minuit UTC du jour butoir ; ni l'un ni l'autre ne bouge.
        [$ancien, [$identifiant]] = $this->createPairedRight(TypeDroitAcces::CarteQuota, 1, 10);
        [$autreJour] = $this->createPairedRight(TypeDroitAcces::CarteQuota, 1, 10);
        [$billet] = $this->createPairedRight(TypeDroitAcces::Billet);
        foreach ([$ancien => '2027-01-31', $autreJour => '2027-01-30', $billet => '2027-01-31'] as $id => $fin) {
            $this->findRight($id)->setProduitRef($produit->getId())->setFenetreFin(new \DateTimeImmutable($fin));
        }
        $this->snapshotEm()->flush();
        $curseur = $this->snapshotCursor();

        // --dry-run, le défaut : liste, n'écrit rien.
        $sortie = $this->runCommand([]);
        self::assertStringContainsString($ancien, $sortie);
        self::assertStringNotContainsString($autreJour, $sortie);
        self::assertStringNotContainsString($billet, $sortie);
        self::assertSame($sortie, $this->runCommand(['--dry-run' => true]));
        self::assertSame('2027-01-31 00:00:00', $this->fin($ancien));
        self::assertSame(0, $this->auditCount($ancien));
        self::assertNull($this->deltaEntry($curseur, $identifiant), '--dry-run ne fait pas avancer la version du support.');

        // --executer : recale, trace, et la borne en delta voit la nouvelle fin.
        self::assertStringContainsString($ancien, $this->runCommand(['--executer' => true]));
        self::assertSame($attendue, $this->assertInDelta($curseur, $identifiant, 'Reprise des échéances')['validiteFin'] ?? null);
        self::assertSame(1, $this->auditCount($ancien));
        self::assertSame('2027-01-30 00:00:00', $this->fin($autreJour));
        self::assertSame('2027-01-31 00:00:00', $this->fin($billet));

        // Idempotente : relancée, elle ne change plus rien.
        $curseur = $this->snapshotCursor();
        self::assertStringNotContainsString($ancien, $this->runCommand(['--executer' => true]));
        self::assertSame(1, $this->auditCount($ancien));
        self::assertNull($this->deltaEntry($curseur, $identifiant));
    }

    /** @param array<string, bool> $options */
    private function runCommand(array $options): string
    {
        $application = new Application(static::bootKernel());
        $application->setAutoExit(false);
        $tester = new CommandTester($application->find('acces:recaler-echeances-cartes'));
        self::assertSame(0, $tester->execute($options));

        return $tester->getDisplay();
    }

    private function fin(string $droit): ?string
    {
        $this->snapshotEm()->clear();

        return $this->findRight($droit)->getFenetreFin()?->format('Y-m-d H:i:s');
    }

    private function auditCount(string $droit): int
    {
        return $this->snapshotEm()->getRepository(EntreeAudit::class)->count(['action' => RealignCardDeadlinesCommand::AUDIT_ACTION, 'cibleId' => $droit]);
    }
}
