<?php

declare(strict_types=1);

namespace App\Tests\Vente\Unit;

use App\Caisse\Entity\PointDeVente;
use App\Vente\Enum\TypeOperationScellee;
use App\Vente\Nf525\HashChainSignataire;
use App\Vente\Nf525\OperationAScellerDto;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * NF525 — signataire par défaut (CA-15) : scellement chaîné (empreinte N calculée depuis N-1),
 * séquence strictement croissante, et détection de rupture par verifieChaine.
 */
final class HashChainTest extends TestCase
{
    public function testScellementChaineEtVerification(): void
    {
        $signataire = new HashChainSignataire('cle-test');
        $pdv = new PointDeVente();

        $op1 = $signataire->scelle($this->dto($pdv, 'a'), null);
        self::assertSame(1, $op1->getNumeroSequence());
        self::assertNull($op1->getEmpreintePrecedente());
        self::assertNotSame('', $op1->getEmpreinte());
        self::assertNotSame('', $op1->getSignature());

        $op2 = $signataire->scelle($this->dto($pdv, 'b'), $op1);
        self::assertSame(2, $op2->getNumeroSequence());
        self::assertSame($op1->getEmpreinte(), $op2->getEmpreintePrecedente());

        // Chaîne intègre.
        $rapport = $signataire->verifieChaine([$op1, $op2]);
        self::assertTrue($rapport->intacte);
        self::assertSame(2, $rapport->nbOperations);
    }

    public function testDetectionRupture(): void
    {
        $signataire = new HashChainSignataire('cle-test');
        $pdv = new PointDeVente();

        $op1 = $signataire->scelle($this->dto($pdv, 'a'), null);
        $op2 = $signataire->scelle($this->dto($pdv, 'b'), $op1);

        // Altération de la donnée figée : l'empreinte recalculée ne correspond plus.
        $op2->setPayloadCanonique(['montant' => '999.00']);

        $rapport = $signataire->verifieChaine([$op1, $op2]);
        self::assertFalse($rapport->intacte, 'La rupture doit être détectée (alerte de contrôle).');
        self::assertNotEmpty($rapport->anomalies);
    }

    private function dto(PointDeVente $pdv, string $graine): OperationAScellerDto
    {
        return new OperationAScellerDto(
            $pdv,
            TypeOperationScellee::Vente,
            'Vente',
            Uuid::v4(),
            ['montant' => '10.00', 'graine' => $graine],
        );
    }
}
