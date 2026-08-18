<?php

declare(strict_types=1);

namespace App\Tests\Facturation;

/**
 * CA-7 (RG-FACT-01) : séquence strictement croissante, sans trou ni doublon, par préfixe (FA/AVF) —
 * un brouillon jamais émis ne consomme aucun numéro. Le verrou pessimiste
 * (`GenerateurNumeroFacture::serieVerrouillee`) est exercé à chaque émission ; 20 émissions
 * consécutives valident l'absence de trou/doublon même sous forte cadence.
 */
final class NumerotationFactureTest extends FacturationApiTestCase
{
    public function testCa7SequenceSansTrouNiDoublonFaEtAvf(): void
    {
        [$client, $entete] = $this->adminSurA();

        $numeros = [];
        $premierIdEmis = null;
        for ($i = 0; $i < 20; ++$i) {
            $brouillon = $client->request('POST', '/api/factures', $entete + [
                'json' => $this->corpsFactureDirecte(10.0 + $i),
            ])->toArray();
            $emise = $client->request('POST', '/api/factures/' . $brouillon['id'] . '/emettre', $entete)->toArray();
            $numeros[] = $emise['numero'];
            $premierIdEmis ??= $emise['id'];
        }

        self::assertCount(20, array_unique($numeros), 'Aucun doublon.');
        $sequences = array_map(static fn (string $n): int => (int) substr($n, strrpos($n, '-') + 1), $numeros);
        sort($sequences);
        self::assertSame(range(1, 20), $sequences, 'Séquence continue, sans trou (CA-7).');
        foreach ($numeros as $numero) {
            self::assertStringStartsWith('FA-', $numero);
        }

        // Un brouillon jamais émis ne consomme aucun numéro.
        $brouillonJamaisEmis = $client->request('POST', '/api/factures', $entete + [
            'json' => $this->corpsFactureDirecte(5.0),
        ])->toArray();
        self::assertNull($brouillonJamaisEmis['numero']);

        // Série AVF distincte et indépendante de la série FA : premier avoir démarre à 1.
        $avoir = $client->request('POST', '/api/factures/' . $premierIdEmis . '/avoir', $entete)->toArray();
        self::assertStringStartsWith('AVF-', $avoir['numero']);
        self::assertStringEndsWith('-00001', $avoir['numero']);
    }
}
