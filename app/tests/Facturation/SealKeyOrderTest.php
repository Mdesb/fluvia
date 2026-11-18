<?php

declare(strict_types=1);

namespace App\Tests\Facturation;

use App\Facturation\Einvoicing\InvoiceIssuerSnapshot;
use App\Facturation\Entity\Facture;
use App\Facturation\Nf525\ScellementFactureHandler;
use App\Platform\Nf525\SealKeyring;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * #369 / G-3 — ORDRE DES CLÉS sur la chaîne de facturation, contre la vraie base : une clé que la chaîne
 * a quittée n'y revient jamais, et un maillon sans identifiant ne suit jamais un maillon signé.
 *
 * Quatre factures réellement émises (clé active de l'environnement de test = « A »), dont les maillons
 * sont re-signés EN MÉMOIRE par la clé voulue : chaque forge est chaînée et signée correctement, seule la
 * règle d'ordre peut la voir. Rien n'est écrit en base.
 */
final class SealKeyOrderTest extends FacturationApiTestCase
{
    private const KEY_B = 'cle-de-test-B-ordre-facturation';
    private const KEY_C = 'cle-de-test-C-ordre-facturation';
    private const VARIABLE = 'NF525_FACTURATION_SEAL_KEY';

    public function testKeyOrderRegressionIsReportedAtTheRightSequence(): void
    {
        [$chain, $keyA] = $this->realChain(4);
        $verifier = $this->verifier(new SealKeyring(self::VARIABLE, self::KEY_C, $keyA . ',' . self::KEY_B));
        $keys = ['A' => $keyA, 'B' => self::KEY_B, 'C' => self::KEY_C];

        $cases = [
            // [suite des clés, séquences en régression]. Témoins négatifs.
            ['A,A,B,A', [$chain[3]->getNumeroSequence()]],
            ['A,A,B,NULL', [$chain[3]->getNumeroSequence()]],
            ['A,B,A,B', [$chain[2]->getNumeroSequence(), $chain[3]->getNumeroSequence()]],
            // Témoins positifs (G-7 : chaînes légitimes).
            ['A,A,B,B', []],
            ['NULL,NULL,A,A', []],
            ['A,B,C,C', []],
            ['NULL,NULL,NULL,NULL', []],
            ['A,A,A,A', []],
        ];
        foreach ($cases as [$suite, $expected]) {
            foreach (explode(',', $suite) as $i => $name) {
                $this->resign($chain[$i], $name === 'NULL' ? null : $name, $keys, $keyA);
            }

            $rapport = $verifier->verifieChaine($chain);

            $found = [];
            foreach ($rapport['anomalies'] as $anomaly) {
                self::assertStringContainsString('régression de clé', $anomaly['probleme'], $suite . ' : seule la régression est en cause : ' . $anomaly['probleme']);
                self::assertStringNotContainsString('signature invalide', $anomaly['probleme']);
                self::assertStringNotContainsString('clé inconnue', $anomaly['probleme']);
                foreach ($keys as $key) {
                    self::assertStringNotContainsString($key, $anomaly['probleme'], 'jamais une clé dans un message');
                }
                $found[] = $anomaly['sequence'];
            }
            self::assertSame($expected, $found, $suite);
            self::assertSame($expected === [], $rapport['intacte'], $suite);
            self::assertSame(4, $rapport['nbDocuments']);
        }

        // Le message nomme les identifiants des clés concernées, jamais les clés.
        foreach (['A', 'B', 'A', 'A'] as $i => $name) {
            $this->resign($chain[$i], $name, $keys, $keyA);
        }
        $message = $verifier->verifieChaine($chain)['anomalies'][0]['probleme'];
        self::assertStringContainsString(SealKeyring::keyIdOf($keyA), $message);
        self::assertStringContainsString(SealKeyring::keyIdOf(self::KEY_B), $message);

        $this->em()->clear(); // les maillons re-signés « à la main » ne sont jamais persistés.
    }

    public function testNonIncreasingSequencesBecomeAnAnomalyNotAnException(): void
    {
        [$chain, $keyA] = $this->realChain(3);
        $verifier = $this->verifier(new SealKeyring(self::VARIABLE, $keyA));

        $chain[2]->setNumeroSequence($chain[1]->getNumeroSequence()); // doublon forgé

        $rapport = $verifier->verifieChaine($chain);

        self::assertFalse($rapport['intacte'], 'échec fermé');
        self::assertNotEmpty(array_filter(
            array_column($rapport['anomalies'], 'probleme'),
            static fn (string $p): bool => str_contains($p, 'non strictement croissants'),
        ));
        $this->em()->clear();
    }

    /**
     * @return array{0: list<Facture>, 1: string} la chaîne de l'exploitant, et la clé active de l'environnement
     */
    private function realChain(int $length): array
    {
        [$client, $entete] = $this->adminSurA();
        $chain = [];
        for ($i = 0; $i < $length; ++$i) {
            $brouillon = $client->request('POST', '/api/factures', $entete + ['json' => $this->corpsFactureDirecte(100.0 + $i)])->toArray();
            $emise = $client->request('POST', '/api/factures/' . $brouillon['id'] . '/emettre', $entete)->toArray();
            $facture = $this->em()->getRepository(Facture::class)->find(Uuid::fromString((string) $emise['id']));
            self::assertNotNull($facture);
            $chain[] = $facture;
        }
        usort($chain, static fn (Facture $a, Facture $b): int => $a->getNumeroSequence() <=> $b->getNumeroSequence());

        $keyA = $_SERVER[self::VARIABLE] ?? $_ENV[self::VARIABLE] ?? getenv(self::VARIABLE);
        self::assertIsString($keyA);

        return [$chain, $keyA];
    }

    private function verifier(SealKeyring $keyring): ScellementFactureHandler
    {
        /** @var InvoiceIssuerSnapshot $snapshot */
        $snapshot = static::getContainer()->get(InvoiceIssuerSnapshot::class);

        return new ScellementFactureHandler($this->em(), $keyring, $snapshot);
    }

    /**
     * Re-signe le maillon par la clé nommée (`null` : signature SANS identifiant, comme avant le trousseau,
     * par la clé « A »). L'empreinte et le chaînage ne changent pas.
     *
     * @param array<string, string> $keys
     */
    private function resign(Facture $f, ?string $name, array $keys, string $keyA): void
    {
        if ($name === null) {
            $f->setSealKeyId(null)->setSignature((new SealKeyring(self::VARIABLE, $keyA))->signWithoutId($f->getEmpreinte()));

            return;
        }
        $ring = new SealKeyring(self::VARIABLE, $keys[$name]);
        $f->setSealKeyId($ring->activeKeyId())->setSignature($ring->sign($f->getEmpreinte()));
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
