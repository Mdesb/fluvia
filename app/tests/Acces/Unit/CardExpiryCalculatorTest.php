<?php

declare(strict_types=1);

namespace App\Tests\Acces\Unit;

use App\Acces\Service\CardExpiryCalculator;
use App\Offre\Entity\CarteMultiEntrees;
use PHPUnit\Framework\TestCase;

/**
 * RG-CQ1-04 (D26, défaut) : les quatre branches du calcul de la nouvelle échéance, et la
 * non-dépendance à `$fenetreFinActuelle` en dehors du point d'extension CQ-7.
 */
final class CardExpiryCalculatorTest extends TestCase
{
    private CardExpiryCalculator $calculateur;
    private \DateTimeImmutable $maintenant;

    protected function setUp(): void
    {
        $this->calculateur = new CardExpiryCalculator();
        $this->maintenant = new \DateTimeImmutable('2026-08-23 10:00:00');
    }

    public function testDureeSeuleUnePeriodeCompleteDepuisMaintenant(): void
    {
        $carte = (new CarteMultiEntrees())->setValiditeDuree(new \DateInterval('P1Y'));

        $resultat = $this->calculateur->calculer($carte, null, $this->maintenant);

        self::assertEquals(new \DateTimeImmutable('2027-08-23 10:00:00'), $resultat);
    }

    public function testDureeEtButoirPlusProcheEstPlafonne(): void
    {
        $carte = (new CarteMultiEntrees())
            ->setValiditeDuree(new \DateInterval('P1Y'))
            ->setDateButoir(new \DateTimeImmutable('2026-10-23'));

        $resultat = $this->calculateur->calculer($carte, null, $this->maintenant);

        self::assertEquals(new \DateTimeImmutable('2026-10-23'), $resultat, 'Le butoir plus proche plafonne.');
    }

    public function testDureeEtButoirPlusLointainNestPasRetenu(): void
    {
        $carte = (new CarteMultiEntrees())
            ->setValiditeDuree(new \DateInterval('P1Y'))
            ->setDateButoir(new \DateTimeImmutable('2030-01-01'));

        $resultat = $this->calculateur->calculer($carte, null, $this->maintenant);

        self::assertEquals(new \DateTimeImmutable('2027-08-23 10:00:00'), $resultat, 'Le butoir lointain ne plafonne pas.');
    }

    public function testButoirSeulEstRetourneTelQuel(): void
    {
        $carte = (new CarteMultiEntrees())->setDateButoir(new \DateTimeImmutable('2026-12-31'));

        $resultat = $this->calculateur->calculer($carte, null, $this->maintenant);

        self::assertEquals(new \DateTimeImmutable('2026-12-31'), $resultat);
    }

    public function testAucunDesDeuxRenvoieNull(): void
    {
        $carte = new CarteMultiEntrees();

        self::assertNull($this->calculateur->calculer($carte, null, $this->maintenant));
    }

    public function testFenetreFinActuelleNInterviantDansAucuneBranche(): void
    {
        $carte = (new CarteMultiEntrees())->setValiditeDuree(new \DateInterval('P1Y'));
        $ancienneEcheance = new \DateTimeImmutable('2026-08-26'); // J + 3 jours (CA-3)

        $resultat = $this->calculateur->calculer($carte, $ancienneEcheance, $this->maintenant);

        self::assertEquals(
            new \DateTimeImmutable('2027-08-23 10:00:00'),
            $resultat,
            'J + 1 an, jamais « ancienne échéance + 1 an ».'
        );
    }
}
