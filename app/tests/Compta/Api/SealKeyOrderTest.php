<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\Journal;
use App\Compta\Nf525\ScellementEcritureHandler;
use App\Platform\Nf525\SealKeyring;
use App\Tests\Compta\ComptaApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * #369 / G-2 — ORDRE DES CLÉS sur la chaîne comptable, contre la vraie base : une clé que la chaîne a
 * quittée n'y revient jamais, et un maillon sans identifiant ne suit jamais un maillon signé.
 *
 * Quatre écritures réelles (clé active de l'environnement de test = « A »), dont les maillons sont
 * re-signés EN MÉMOIRE par la clé voulue : chaque forge est donc chaînée et signée correctement, et seule
 * la règle d'ordre peut la voir. Rien n'est écrit en base.
 */
final class SealKeyOrderTest extends ComptaApiTestCase
{
    private const KEY_B = 'cle-de-test-B-ordre-compta';
    private const KEY_C = 'cle-de-test-C-ordre-compta';
    private const VARIABLE = 'NF525_COMPTA_SEAL_KEY';

    public function testKeyOrderRegressionIsReportedAtTheRightSequence(): void
    {
        [$chain, $keyA] = $this->realChain(4);
        $verifier = new ScellementEcritureHandler($this->em(), new SealKeyring(self::VARIABLE, self::KEY_C, $keyA . ',' . self::KEY_B));
        $keys = ['A' => $keyA, 'B' => self::KEY_B, 'C' => self::KEY_C];

        $cases = [
            // [suite des clés, séquences en régression]. Témoins négatifs.
            ['A,A,B,A', [4]],
            ['A,A,B,NULL', [4]],
            ['A,B,A,B', [3, 4]],
            // Témoins positifs (G-7 : chaînes légitimes).
            ['A,A,B,B', []],
            ['NULL,NULL,A,A', []],
            ['A,B,C,C', []],
            ['NULL,NULL,NULL,NULL', []],
            ['A,A,A,A', []],
        ];
        foreach ($cases as [$suite, $expected]) {
            $names = explode(',', $suite);
            foreach ($names as $i => $name) {
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
            self::assertSame(4, $rapport['nbOperations']);
        }

        // Le message nomme les identifiants des clés concernées, jamais les clés.
        $this->resign($chain[0], 'A', $keys, $keyA);
        $this->resign($chain[1], 'B', $keys, $keyA);
        $this->resign($chain[2], 'A', $keys, $keyA);
        $this->resign($chain[3], 'A', $keys, $keyA);
        $message = $verifier->verifieChaine($chain)['anomalies'][0]['probleme'];
        self::assertStringContainsString(SealKeyring::keyIdOf($keyA), $message);
        self::assertStringContainsString(SealKeyring::keyIdOf(self::KEY_B), $message);

        $this->em()->clear(); // les maillons re-signés « à la main » ne sont jamais persistés.
    }

    public function testNonIncreasingSequencesBecomeAnAnomalyNotAnException(): void
    {
        [$chain, $keyA] = $this->realChain(3);
        $verifier = new ScellementEcritureHandler($this->em(), new SealKeyring(self::VARIABLE, $keyA));

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
     * @return array{0: list<EcritureComptable>, 1: string} la chaîne du journal des ventes, et la clé active de l'environnement
     */
    private function realChain(int $length): array
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        for ($i = 0; $i < $length; ++$i) {
            $this->creerVenteValidee($client, $entete, quantite: 1, sessionId: $session['id']);
        }
        $client->request('POST', '/api/compta/ecritures/generer', $entete + [
            'json' => ['profilExploitant' => '/api/profil_exploitants/' . $this->idProfilExploitant()],
        ]);

        $journal = $this->em()->getRepository(Journal::class)->findOneBy(['code' => 'VTE']);
        self::assertNotNull($journal);
        $chain = $this->em()->getRepository(EcritureComptable::class)->findBy(['journal' => $journal->getId()], ['numeroSequence' => 'ASC']);
        self::assertCount($length, $chain);

        $keyA = $_SERVER['NF525_COMPTA_SEAL_KEY'] ?? $_ENV['NF525_COMPTA_SEAL_KEY'] ?? getenv('NF525_COMPTA_SEAL_KEY');
        self::assertIsString($keyA);

        return [array_values($chain), $keyA];
    }

    /**
     * Re-signe le maillon par la clé nommée (`null` : signature SANS identifiant, comme avant le trousseau,
     * par la clé « A »). L'empreinte et le chaînage ne changent pas.
     *
     * @param array<string, string> $keys
     */
    private function resign(EcritureComptable $e, ?string $name, array $keys, string $keyA): void
    {
        if ($name === null) {
            $e->setSealKeyId(null)->setSignature((new SealKeyring(self::VARIABLE, $keyA))->signWithoutId($e->getEmpreinte()));

            return;
        }
        $ring = new SealKeyring(self::VARIABLE, $keys[$name]);
        $e->setSealKeyId($ring->activeKeyId())->setSignature($ring->sign($e->getEmpreinte()));
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
