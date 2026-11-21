<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\Journal;
use App\Compta\Nf525\ScellementEcritureHandler;
use App\Platform\Nf525\SealingRefusedException;
use App\Platform\Nf525\SealKeyOrder;
use App\Platform\Nf525\SealKeyring;
use App\Tests\Compta\ComptaApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\AbstractLogger;
use Symfony\Component\Uid\Uuid;

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
     * #369 / G-5 + G-7 — LE REFUS DE SCELLER, sur la chaîne PERSISTÉE : les maillons réels sont réécrits en SQL
     * (identifiant de clé et signature valides, comme après une vraie rotation), puis `sceller` est appelé.
     * Refusé = 409, rien de scellé, une seule alerte critical sans clé ; accepté = aucune alerte.
     */
    public function testSealingIsRefusedOrAllowedAccordingToTheKeyOrder(): void
    {
        [$chain, $keyA] = $this->realChain(4);
        $keys = ['A' => $keyA, 'B' => self::KEY_B, 'C' => self::KEY_C];
        $ids = array_map(static fn (EcritureComptable $e) => $e->getId(), $chain);
        $profilId = $chain[0]->getProfilExploitant()?->getId();
        $journalId = $chain[0]->getJournal()?->getId();
        self::assertNotNull($profilId);
        self::assertNotNull($journalId);

        $cases = [
            // [suite, clé active, clés retirées, attendu]. Témoins négatifs.
            ['A,A,B,B', 'A', ['B'], SealKeyOrder::BLOCK_ACTIVE_KEY_LEFT],
            ['A,B,B,B', 'A', ['B'], SealKeyOrder::BLOCK_ACTIVE_KEY_LEFT],
            ['A,B,B,A', 'C', ['A', 'B'], SealKeyOrder::BLOCK_LAST_LINK_REGRESSED],
            ['A,A,A,NULL', 'B', ['A'], SealKeyOrder::BLOCK_LAST_LINK_REGRESSED],
            ['A,A,B,NULL', 'C', ['A', 'B'], SealKeyOrder::BLOCK_LAST_LINK_REGRESSED],
            // Témoins positifs (G-7 : chaînes légitimes, aucune alerte).
            ['NULL,NULL,A,A', 'A', [], null],
            ['NULL,NULL,NULL,NULL', 'A', [], null],
            ['A,A,A,A', 'A', [], null],
            ['A,A,B,B', 'B', ['A'], null],
            ['A,A,B,B', 'C', ['A', 'B'], null],
            ['NULL,NULL,A,A', 'B', ['A'], null],
        ];
        foreach ($cases as [$suite, $active, $retired, $expected]) {
            foreach (explode(',', $suite) as $i => $name) {
                $this->persistKey($ids[$i], $chain[$i]->getEmpreinte(), $name === 'NULL' ? null : $name, $keys, $keyA);
            }
            $this->em()->clear();

            [$handler, $logger] = $this->handlerFor($active, $retired, $keys);
            $draft = $this->draft($profilId, $journalId);
            $label = sprintf('%s, active %s', $suite, $active);

            if ($expected === null) {
                $handler->sceller($draft);
                self::assertSame(5, $draft->getNumeroSequence(), $label);
                self::assertSame(SealKeyring::keyIdOf($keys[$active]), $draft->getSealKeyId(), $label);
                self::assertSame([], $logger->records, $label . ' : aucune alerte sur une chaîne légitime');
                continue;
            }
            try {
                $handler->sceller($draft);
                self::fail($label . ' : le scellement aurait dû être refusé.');
            } catch (SealingRefusedException $e) {
                self::assertSame(409, $e->getStatusCode(), $label);
                self::assertSame($expected === SealKeyOrder::BLOCK_ACTIVE_KEY_LEFT, str_contains($e->technicalDetail(), 'NEUVE'), $label . ' : « clé NEUVE » seulement quand la chaîne a quitté la clé active');
                $message = $e->technicalDetail();
                foreach ($keys as $key) {
                    self::assertStringNotContainsString($key, $message . json_encode($logger->records, \JSON_THROW_ON_ERROR), $label);
                    self::assertStringNotContainsString($key, $e->getMessage(), $label . ' : le message rendu ne contient pas la clé');
                    self::assertStringNotContainsString(SealKeyring::keyIdOf($key), $e->getMessage(), $label . ' : ni un identifiant de clé');
                }
                self::assertStringNotContainsString('NF525_', $e->getMessage(), $label);
            }
            self::assertSame(0, $draft->getNumeroSequence(), $label . ' : rien n\'est scellé');
            self::assertCount(1, $logger->records, $label);
            self::assertSame('critical', $logger->records[0][0], $label);
            self::assertSame($expected, $logger->records[0][2]['cause'], $label);
        }
    }

    /** G-7 + cloisonnement : une chaîne en régression ne bloque ni un autre journal, ni la rotation de bout en bout. */
    public function testRotationEndToEndAndAnotherJournalIsNotBlocked(): void
    {
        [$chain, $keyA] = $this->realChain(4);
        $keys = ['A' => $keyA, 'B' => self::KEY_B, 'C' => self::KEY_C];
        $ids = array_map(static fn (EcritureComptable $e) => $e->getId(), $chain);
        $profilId = $chain[0]->getProfilExploitant()?->getId();
        $journalId = $chain[0]->getJournal()?->getId();
        self::assertNotNull($profilId);
        self::assertNotNull($journalId);
        $this->em()->clear();

        // A actif, chaîne de A : se prolonge. Rotation vers B (A retirée) : se prolonge.
        [$a] = $this->handlerFor('A', [], $keys);
        $a->sceller($this->draft($profilId, $journalId));
        [$b] = $this->handlerFor('B', ['A'], $keys);
        $draft = $this->draft($profilId, $journalId);
        $b->sceller($draft);
        self::assertSame(SealKeyring::keyIdOf(self::KEY_B), $draft->getSealKeyId());

        // La chaîne porte maintenant des maillons de B (réécriture des deux derniers) : B poursuit, A est refusée.
        foreach ([2, 3] as $i) {
            $this->persistKey($ids[$i], $chain[$i]->getEmpreinte(), 'B', $keys, $keyA);
        }
        $this->em()->clear();
        $b->sceller($this->draft($profilId, $journalId));
        [$backToA, $logger] = $this->handlerFor('A', ['B'], $keys);
        $refused = $this->draft($profilId, $journalId);
        try {
            $backToA->sceller($refused);
            self::fail('Le retour à la clé A aurait dû être refusé.');
        } catch (SealingRefusedException $e) {
            self::assertSame(409, $e->getStatusCode());
        }
        self::assertSame(0, $refused->getNumeroSequence());
        self::assertCount(1, $logger->records);

        // Cloisonnement : un autre journal du même exploitant, sans maillon, n'est pas touché par cette chaîne.
        $other = null;
        foreach ($this->em()->getRepository(Journal::class)->findAll() as $candidate) {
            if ((string) $candidate->getId() !== (string) $journalId) {
                $other = $candidate;
                break;
            }
        }
        self::assertNotNull($other, 'témoin : le jeu de test porte un second journal');
        $draftOther = $this->draft($profilId, $other->getId());
        $backToA->sceller($draftOther);
        self::assertSame(1, $draftOther->getNumeroSequence(), 'un autre journal repart de 1 : la chaîne en régression ne le bloque pas');
        $this->em()->clear();
    }

    /**
     * Une écriture sans exploitant ni journal est une ERREUR DE PROGRAMMATION (LogicException), pas une alerte
     * fiscale : elle n'est pas maquillée en refus `unreadable_order` (409), et ne journalise rien.
     */
    public function testAChainWithoutProfilOrJournalIsAProgrammingErrorNotAFiscalAlert(): void
    {
        [$chain, $keyA] = $this->realChain(2);
        $logger = new class extends AbstractLogger {
            public int $count = 0;

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                ++$this->count;
            }
        };
        $handler = new ScellementEcritureHandler($this->em(), new SealKeyring(self::VARIABLE, $keyA, null, $logger));
        $orphan = new EcritureComptable();
        try {
            $handler->sceller($orphan);
            self::fail('Une écriture sans exploitant ni journal ne peut pas être scellée.');
        } catch (SealingRefusedException) {
            self::fail('Une erreur de programmation ne doit pas être maquillée en refus fiscal.');
        } catch (\LogicException) {
            self::assertSame(0, $logger->count, 'aucune alerte fiscale pour un bug d\'appelant');
        }
        self::assertSame(0, $orphan->getNumeroSequence());
        self::assertNotEmpty($chain);
    }

    private function persistKey(mixed $id, string $fingerprint, ?string $name, array $keys, string $keyA): void
    {
        $ring = new SealKeyring(self::VARIABLE, $name === null ? $keyA : $keys[$name]);
        $this->em()->getConnection()->executeStatement(
            'UPDATE compta_ecriture_comptable SET seal_key_id = ?, signature = ? WHERE id = ?',
            [$name === null ? null : $ring->activeKeyId(), $name === null ? $ring->signWithoutId($fingerprint) : $ring->sign($fingerprint), Uuid::fromString((string) $id)->toBinary()],
        );
    }

    /**
     * @param list<string>          $retired
     * @param array<string, string> $keys
     *
     * @return array{0: ScellementEcritureHandler, 1: AbstractLogger&object{records: list<array{string, string, array<mixed>}>}}
     */
    private function handlerFor(string $active, array $retired, array $keys): array
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, string, array<mixed>}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message, $context];
            }
        };
        $ring = new SealKeyring(self::VARIABLE, $keys[$active], implode(',', array_map(static fn (string $n): string => $keys[$n], $retired)), $logger);

        return [new ScellementEcritureHandler($this->em(), $ring), $logger];
    }

    private function draft(mixed $profilId, mixed $journalId): EcritureComptable
    {
        $profil = $this->em()->find(\App\Compta\Entity\ProfilExploitant::class, $profilId);
        $journal = $this->em()->find(Journal::class, $journalId);
        self::assertNotNull($profil);
        self::assertNotNull($journal);

        return (new EcritureComptable())->setProfilExploitant($profil)->setJournal($journal);
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
