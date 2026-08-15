<?php

declare(strict_types=1);

namespace App\Tests\Recouvrement\Unit;

use App\Acces\Entity\DroitAcces;
use App\Organisation\Entity\Etablissement;
use App\Recouvrement\Port\RedevablePort;
use App\Recouvrement\Service\RedevableRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Non-régression architecturale : le moteur de recouvrement (`App\Recouvrement`) ne connaît aucune
 * verticale — il ne fonctionne qu'au travers du port `RedevablePort`, agrégé par `RedevableRegistry`.
 * Ce test emploie deux ports factices (aucune dépendance à `App\Sport`) pour prouver la généricité.
 */
final class RedevableRegistryTest extends TestCase
{
    public function testResoutLePortCorrespondantAuTypeRedevable(): void
    {
        $droitA = $this->createMock(DroitAcces::class);
        $etabA = $this->createMock(Etablissement::class);

        $portA = new class($droitA, $etabA) implements RedevablePort {
            public function __construct(private DroitAcces $droit, private Etablissement $etab)
            {
            }

            public function typeRedevable(): string
            {
                return 'demo.contrat_a';
            }

            public function droitAcces(string $referenceRedevable): ?DroitAcces
            {
                return $referenceRedevable === 'ref-a' ? $this->droit : null;
            }

            public function etablissement(string $referenceRedevable): ?Etablissement
            {
                return $referenceRedevable === 'ref-a' ? $this->etab : null;
            }

            public function estLieA(string $referenceRedevable, mixed $utilisateur): bool
            {
                return $referenceRedevable === 'ref-a' && $utilisateur === 'user-a';
            }
        };

        $portB = new class implements RedevablePort {
            public function typeRedevable(): string
            {
                return 'demo.contrat_b';
            }

            public function droitAcces(string $referenceRedevable): ?DroitAcces
            {
                return null;
            }

            public function etablissement(string $referenceRedevable): ?Etablissement
            {
                return null;
            }

            public function estLieA(string $referenceRedevable, mixed $utilisateur): bool
            {
                return false;
            }
        };

        $registre = new RedevableRegistry([$portA, $portB]);

        self::assertSame($droitA, $registre->droitAcces('demo.contrat_a', 'ref-a'));
        self::assertNull($registre->droitAcces('demo.contrat_a', 'ref-inconnue'));
        self::assertNull($registre->droitAcces('demo.contrat_b', 'ref-a'));
        self::assertNull($registre->droitAcces('demo.type_non_enregistre', 'ref-a'));

        self::assertSame($etabA, $registre->etablissement('demo.contrat_a', 'ref-a'));
        self::assertTrue($registre->estLieA('demo.contrat_a', 'ref-a', 'user-a'));
        self::assertFalse($registre->estLieA('demo.contrat_a', 'ref-a', 'user-autre'));
        self::assertFalse($registre->estLieA('demo.type_non_enregistre', 'ref-a', 'user-a'));
    }
}
