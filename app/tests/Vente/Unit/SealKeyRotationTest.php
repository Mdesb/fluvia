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
    private const KEY_C = 'cle-de-test-C-vente';

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

    // ---- #369 : ORDRE DES CLÉS (G-1) — témoin négatif ET témoin positif de chaque cas ----

    /**
     * @return iterable<string, array{0: list<string|null>, 1: list<int>}> suite des clés ('A','B','C', null), séquences en régression
     */
    public static function orderCases(): iterable
    {
        // Témoins négatifs.
        yield 'A,A,B,A : clé quittée qui revient' => [['A', 'A', 'B', 'A'], [4]];
        yield 'A,A,B,NULL : maillon sans identifiant après une clé' => [['A', 'A', 'B', null], [4]];
        yield 'A,B,A,B : deux régressions' => [['A', 'B', 'A', 'B'], [3, 4]];
        // Témoins positifs (G-7 : chaînes légitimes).
        yield 'A,A,B,B : rotation légitime' => [['A', 'A', 'B', 'B'], []];
        yield 'NULL,NULL,A : chaîne héritée puis première clé' => [[null, null, 'A'], []];
        yield 'A,B,C : trois rotations' => [['A', 'B', 'C'], []];
        yield 'NULL,NULL : héritée seule' => [[null, null], []];
        yield 'A,A : une seule clé' => [['A', 'A'], []];
    }

    /**
     * @param list<string|null> $keys
     * @param list<int>         $expectedRegressions
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('orderCases')]
    public function testKeyOrderRegressionIsReportedAtTheRightSequence(array $keys, array $expectedRegressions): void
    {
        $chain = $this->chainSignedBy($keys);
        // Le trousseau du vérificateur contient toutes les clés : seule la RÉGRESSION peut être en cause.
        $rapport = $this->signataire(self::KEY_C, self::KEY_A . ',' . self::KEY_B)->verifieChaine($chain);

        $found = [];
        foreach ($rapport->anomalies as $anomaly) {
            self::assertStringContainsString('régression de clé', $anomaly['probleme'], 'seule la régression est en cause : ' . $anomaly['probleme']);
            self::assertStringNotContainsString('signature invalide', $anomaly['probleme']);
            self::assertStringNotContainsString('clé inconnue', $anomaly['probleme']);
            self::assertSame(TypeOperationScellee::Vente->value, $anomaly['type']);
            $found[] = $anomaly['sequence'];
        }
        self::assertSame($expectedRegressions, $found);
        self::assertSame($expectedRegressions === [], $rapport->intacte);
        self::assertSame(\count($keys), $rapport->nbOperations);
    }

    public function testRegressionMessageNamesIdsNeverTheKeys(): void
    {
        $rapport = $this->signataire(self::KEY_C, self::KEY_A . ',' . self::KEY_B)->verifieChaine($this->chainSignedBy(['A', 'B', 'A']));

        $message = $rapport->anomalies[0]['probleme'];
        self::assertStringContainsString(SealKeyring::keyIdOf(self::KEY_A), $message);
        self::assertStringContainsString(SealKeyring::keyIdOf(self::KEY_B), $message);
        foreach ([self::KEY_A, self::KEY_B, self::KEY_C] as $key) {
            self::assertStringNotContainsString($key, $message);
        }
    }

    public function testRegressionIsReportedEvenWhenTheLinkHasAnotherAnomaly(): void
    {
        $chain = $this->chainSignedBy(['A', 'B', 'A']);
        $chain[2]->setSignature('alteree'); // le maillon forgé porte aussi une signature invalide

        $rapport = $this->signataire(self::KEY_C, self::KEY_A . ',' . self::KEY_B)->verifieChaine($chain);

        $problems = array_column($rapport->anomalies, 'probleme');
        self::assertContains('signature invalide', $problems);
        self::assertCount(1, array_filter($problems, static fn (string $p): bool => str_contains($p, 'régression de clé')));
    }

    public function testNonIncreasingSequencesBecomeAnAnomalyNotAnException(): void
    {
        $chain = $this->chainSignedBy(['A', 'A', 'A']);
        $chain[2]->setNumeroSequence(2); // doublon forgé : deux maillons au rang 2

        $rapport = $this->signataire(self::KEY_A)->verifieChaine($chain);

        self::assertFalse($rapport->intacte, 'échec fermé');
        self::assertNotEmpty(array_filter(
            array_column($rapport->anomalies, 'probleme'),
            static fn (string $p): bool => str_contains($p, 'non strictement croissants'),
        ));
    }

    public function testSegmentVerificationAppliesTheRuleInsideTheSegment(): void
    {
        $chain = $this->chainSignedBy(['A', 'A', 'B', 'A']);
        $segment = \array_slice($chain, 1); // le segment d'une archive commence au rang 2
        $verifier = $this->signataire(self::KEY_C, self::KEY_A . ',' . self::KEY_B);

        $rapport = $verifier->verifieSegment($segment, $chain[0]->getEmpreinte(), 2);
        self::assertFalse($rapport->intacte);
        self::assertSame([4], array_column($rapport->anomalies, 'sequence'));

        // Témoin positif : le même segment sans la forge.
        $sain = $this->chainSignedBy(['A', 'A', 'B', 'B']);
        self::assertTrue($verifier->verifieSegment(\array_slice($sain, 1), $sain[0]->getEmpreinte(), 2)->intacte);
    }

    /**
     * Chaîne correctement chaînée dont chaque maillon est signé par la clé indiquée ; `null` = maillon
     * sans identifiant (signé comme avant le trousseau, par la clé A). Les forgeries de l'ordre sont
     * donc VALIDES partout ailleurs : seule la règle d'ordre peut les voir. (Le signataire de chaque
     * maillon connaît toutes les clés : la forge est celle d'un attaquant qui les détient.)
     *
     * @param list<string|null> $keys
     *
     * @return list<\App\Vente\Nf525\Entity\OperationScellee>
     */
    private function chainSignedBy(array $keys): array
    {
        $map = ['A' => self::KEY_A, 'B' => self::KEY_B, 'C' => self::KEY_C];
        $chain = [];
        $previous = null;
        foreach ($keys as $i => $name) {
            $op = $this->signataire($map[$name ?? 'A'], implode(',', $map))->scelle($this->dto('m' . $i), $previous);
            if ($name === null) {
                $op->setSealKeyId(null)->setSignature(hash_hmac('sha256', $op->getEmpreinte(), self::KEY_A));
            }
            $chain[] = $op;
            $previous = $op;
        }

        return $chain;
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
