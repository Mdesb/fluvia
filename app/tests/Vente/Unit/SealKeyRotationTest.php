<?php

declare(strict_types=1);

namespace App\Tests\Vente\Unit;

use App\Caisse\Entity\PointDeVente;
use App\Platform\Nf525\SealingRefusedException;
use App\Platform\Nf525\SealKeyring;
use App\Vente\Enum\TypeOperationScellee;
use App\Vente\Nf525\FiscalArchiveSigner;
use App\Vente\Nf525\HashChainSignataire;
use App\Vente\Nf525\OperationAScellerDto;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * #361 — TÉMOIN À DEUX CLÉS sur la chaîne de vente.
 *
 *  - rotation AVEC trousseau (A retirée, B active) : la chaîne entière se vérifie, et se prolonge ;
 *  - rotation SANS trousseau (B seule) : la vérification dit « clé inconnue », et le scellement est
 *    REFUSÉ — on ne signe pas par-dessus une chaîne qu'on ne peut plus vérifier ;
 *  - témoin : même clé, rien ne change.
 */
final class SealKeyRotationTest extends TestCase
{
    private const KEY_A = 'cle-de-test-A-vente';
    private const KEY_B = 'cle-de-test-B-vente';

    public function testEachLinkCarriesTheIdOfItsKeyNeverTheKey(): void
    {
        $signataire = $this->signataire(self::KEY_A);
        $op1 = $signataire->scelle($this->dto('a'), null);

        self::assertSame(SealKeyring::keyIdOf(self::KEY_A), $op1->getSealKeyId());
        self::assertStringNotContainsString(self::KEY_A, (string) $op1->getSealKeyId());
    }

    public function testRotationWithKeyringKeepsTheChainVerifiableAndExtendable(): void
    {
        [$op1, $op2] = $this->chainSignedByA();

        $rotated = $this->signataire(self::KEY_B, self::KEY_A);
        $op3 = $rotated->scelle($this->dto('c'), $op2);

        self::assertSame(3, $op3->getNumeroSequence());
        self::assertSame(SealKeyring::keyIdOf(self::KEY_B), $op3->getSealKeyId(), 'le nouveau maillon est signé par la clé active');

        $rapport = $rotated->verifieChaine([$op1, $op2, $op3]);
        self::assertTrue($rapport->intacte, 'chaîne à deux clés vérifiable : ' . json_encode($rapport->anomalies, \JSON_UNESCAPED_UNICODE));
        self::assertSame(3, $rapport->nbOperations);
    }

    public function testRotationWithoutKeyringRefusesToSeal(): void
    {
        [$op1, $op2] = $this->chainSignedByA();

        $withoutA = $this->signataire(self::KEY_B);

        $rapport = $withoutA->verifieChaine([$op1, $op2]);
        self::assertFalse($rapport->intacte);
        self::assertCount(2, $rapport->anomalies);
        self::assertStringContainsString('clé inconnue du trousseau', $rapport->anomalies[0]['probleme']);

        $this->expectException(SealingRefusedException::class);
        $this->expectExceptionMessage('Scellement refusé');
        $withoutA->scelle($this->dto('c'), $op2);
    }

    public function testErasingTheKeyIdOfARecentLinkIsDetected(): void
    {
        [$op1, $op2] = $this->chainSignedByA();
        $op2->setSealKeyId(null);

        $rapport = $this->signataire(self::KEY_B, self::KEY_A)->verifieChaine([$op1, $op2]);
        self::assertFalse($rapport->intacte);
        self::assertSame('signature invalide', $rapport->anomalies[0]['probleme']);
    }

    public function testSameKeyWitnessStillExtends(): void
    {
        [$op1, $op2] = $this->chainSignedByA();

        $op3 = $this->signataire(self::KEY_A)->scelle($this->dto('c'), $op2);

        self::assertTrue($this->signataire(self::KEY_A)->verifieChaine([$op1, $op2, $op3])->intacte);
    }

    public function testLegacyLinkWithoutIdIsVerifiedWithTheActiveKey(): void
    {
        [$op1, $op2] = $this->chainSignedByA();
        // Maillons antérieurs au trousseau : aucun identifiant en base, signature sans identifiant.
        foreach ([$op1, $op2] as $op) {
            $op->setSealKeyId(null)->setSignature(hash_hmac('sha256', $op->getEmpreinte(), self::KEY_A));
        }

        self::assertTrue($this->signataire(self::KEY_A)->verifieChaine([$op1, $op2])->intacte, 'compatibilité : clé active');
        $op3 = $this->signataire(self::KEY_A)->scelle($this->dto('c'), $op2);
        self::assertSame(3, $op3->getNumeroSequence());

        // Après rotation, l'ancien maillon sans identifiant se vérifie encore grâce à la clé retirée.
        self::assertTrue($this->signataire(self::KEY_B, self::KEY_A)->verifieChaine([$op1, $op2])->intacte);
        // Et sans elle, le scellement est refusé.
        $this->expectException(SealingRefusedException::class);
        $this->signataire(self::KEY_B)->scelle($this->dto('d'), $op2);
    }

    public function testFiscalArchiveSignedBeforeRotationStaysVerifiable(): void
    {
        $sha = hash('sha256', 'zip');
        $signature = (new FiscalArchiveSigner(new SealKeyring('NF525_SEAL_KEY', self::KEY_A)))->sign($sha);

        self::assertTrue((new FiscalArchiveSigner(new SealKeyring('NF525_SEAL_KEY', self::KEY_B, self::KEY_A)))->verify($sha, $signature));
        self::assertFalse((new FiscalArchiveSigner(new SealKeyring('NF525_SEAL_KEY', self::KEY_B)))->verify($sha, $signature));
    }

    /** @return array{0: \App\Vente\Nf525\Entity\OperationScellee, 1: \App\Vente\Nf525\Entity\OperationScellee} */
    private function chainSignedByA(): array
    {
        $signataire = $this->signataire(self::KEY_A);
        $op1 = $signataire->scelle($this->dto('a'), null);
        $op2 = $signataire->scelle($this->dto('b'), $op1);

        return [$op1, $op2];
    }

    private function signataire(string $active, ?string $retired = null): HashChainSignataire
    {
        return new HashChainSignataire(new SealKeyring('NF525_SEAL_KEY', $active, $retired));
    }

    private PointDeVente $pdv;

    private function dto(string $graine): OperationAScellerDto
    {
        $this->pdv ??= new PointDeVente();

        return new OperationAScellerDto($this->pdv, TypeOperationScellee::Vente, 'Vente', Uuid::v4(), ['montant' => '10.00', 'graine' => $graine]);
    }
}
