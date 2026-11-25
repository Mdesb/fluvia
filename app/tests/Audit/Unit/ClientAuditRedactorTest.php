<?php

declare(strict_types=1);

namespace App\Tests\Audit\Unit;

use App\Audit\Service\ClientAuditRedactor;
use PHPUnit\Framework\TestCase;

/**
 * #363 — la liste des champs d'identité d'un client dans l'audit, et la règle de caviardage d'un tableau.
 */
final class ClientAuditRedactorTest extends TestCase
{
    public function testIdentityFieldsAreTheElevenExpectedOnes(): void
    {
        $expected = ['civilite', 'nom', 'prenom', 'raisonSociale', 'siret', 'email', 'telephone', 'adresse', 'dateNaissance', 'champManuel', 'externalRef'];

        self::assertCount(11, ClientAuditRedactor::IDENTITY_FIELDS);
        self::assertSame([], array_diff($expected, ClientAuditRedactor::IDENTITY_FIELDS));
        self::assertSame([], array_diff(ClientAuditRedactor::IDENTITY_FIELDS, $expected));
        self::assertSame('[caviardé]', ClientAuditRedactor::MARKER);
    }

    public function testNonNullIdentityValuesBecomeTheMarker(): void
    {
        $redactor = $this->redactor();
        $m = ClientAuditRedactor::MARKER;

        $out = $redactor->redactValues([
            'nom' => 'Dupont', 'prenom' => 'Alice', 'email' => 'alice@example.test', 'telephone' => '0102030405',
            'dateNaissance' => '1980-01-02T00:00:00+00:00', 'siret' => '12345678901234', 'civilite' => 'Mme',
            'raisonSociale' => 'Dupont SA', 'externalRef' => 'EXT-1', 'adresse' => 'x', 'champManuel' => 'y',
        ]);

        self::assertEquals(array_fill_keys(ClientAuditRedactor::IDENTITY_FIELDS, $m), array_intersect_key($out, array_flip(ClientAuditRedactor::IDENTITY_FIELDS)));
        self::assertCount(11, $out);
        self::assertStringNotContainsString('Dupont', (string) json_encode($out));
    }

    public function testNullStaysNullAndAbsentKeysAreNotAdded(): void
    {
        $out = $this->redactor()->redactValues(['nom' => null, 'email' => 'a@b.test']);

        self::assertSame(['nom' => null, 'email' => ClientAuditRedactor::MARKER], $out);
        self::assertArrayNotHasKey('prenom', $out);
    }

    public function testNonPersonalFieldsKeysAndOrderAreUntouched(): void
    {
        $in = ['statut' => 'actif', 'nom' => 'Dupont', 'dateMaj' => '2026-01-01T00:00:00+00:00', 'majPar' => 'u@x.test', 'type' => 'physique', 'prenom' => 'Al'];
        $out = $this->redactor()->redactValues($in);

        self::assertSame(array_keys($in), array_keys($out));
        foreach (['statut', 'dateMaj', 'majPar', 'type'] as $key) {
            self::assertSame($in[$key], $out[$key]);
        }
        self::assertSame(ClientAuditRedactor::MARKER, $out['nom']);
    }

    public function testNullInputGivesNull(): void
    {
        self::assertNull($this->redactor()->redactValues(null));
    }

    public function testRedactionIsIdempotent(): void
    {
        $firstRun = $this->redactor()->redactValues(['nom' => 'Dupont', 'statut' => 'actif']);
        $secondRun = $this->redactor()->redactValues($firstRun);

        self::assertSame(['nom' => ClientAuditRedactor::MARKER, 'statut' => 'actif'], $firstRun);
        self::assertSame($firstRun, $secondRun);
    }

    public function testEmptyStringAndZeroAreNonNullSoTheyAreRedacted(): void
    {
        $out = $this->redactor()->redactValues(['nom' => '', 'telephone' => 0, 'email' => false]);

        self::assertSame([
            'nom' => ClientAuditRedactor::MARKER, 'telephone' => ClientAuditRedactor::MARKER, 'email' => ClientAuditRedactor::MARKER,
        ], $out);
    }

    private function redactor(): ClientAuditRedactor
    {
        return (new \ReflectionClass(ClientAuditRedactor::class))->newInstanceWithoutConstructor();
    }
}
