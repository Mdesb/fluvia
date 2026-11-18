<?php

declare(strict_types=1);

namespace App\Tests\Facturation;

use App\Facturation\Einvoicing\InvoiceIssuerSnapshot;
use App\Facturation\Entity\Facture;
use App\Facturation\Nf525\ScellementFactureHandler;
use App\Platform\Nf525\SealingRefusedException;
use App\Platform\Nf525\SealKeyOrder;
use App\Platform\Nf525\SealKeyring;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\AbstractLogger;
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
     * #369 / G-5 + G-7 — LE REFUS DE SCELLER, sur la chaîne PERSISTÉE : les documents réels sont réécrits en SQL
     * (identifiant de clé et signature valides, comme après une vraie rotation), puis `sceller` est appelé.
     * Refusé = 409, rien de scellé, une seule alerte critical sans clé ; accepté = aucune alerte.
     */
    public function testSealingIsRefusedOrAllowedAccordingToTheKeyOrder(): void
    {
        [$chain, $keyA] = $this->realChain(4);
        $keys = ['A' => $keyA, 'B' => self::KEY_B, 'C' => self::KEY_C];
        $ids = array_map(static fn (Facture $f) => $f->getId(), $chain);
        $prints = array_map(static fn (Facture $f): string => $f->getEmpreinte(), $chain);
        $draftId = $this->draftId();
        $lastSequence = $chain[3]->getNumeroSequence();

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
                $this->persistKey($ids[$i], $prints[$i], $name === 'NULL' ? null : $name, $keys, $keyA);
            }
            $this->em()->clear();

            [$handler, $logger] = $this->handlerFor($active, $retired, $keys);
            $draft = $this->facture($draftId);
            $label = sprintf('%s, active %s', $suite, $active);

            if ($expected === null) {
                $handler->sceller($draft);
                self::assertSame($lastSequence + 1, $draft->getNumeroSequence(), $label);
                self::assertSame(SealKeyring::keyIdOf($keys[$active]), $draft->getSealKeyId(), $label);
                self::assertSame([], $logger->records, $label . ' : aucune alerte sur une chaîne légitime');
                continue;
            }
            try {
                $handler->sceller($draft);
                self::fail($label . ' : le scellement aurait dû être refusé.');
            } catch (SealingRefusedException $e) {
                self::assertSame(409, $e->getStatusCode(), $label);
                self::assertSame($expected === SealKeyOrder::BLOCK_ACTIVE_KEY_LEFT, str_contains($e->getMessage(), 'NEUVE'), $label . ' : « clé NEUVE » seulement quand la chaîne a quitté la clé active');
                foreach ($keys as $key) {
                    self::assertStringNotContainsString($key, $e->getMessage() . json_encode($logger->records, \JSON_THROW_ON_ERROR), $label);
                }
            }
            self::assertSame(0, $draft->getNumeroSequence(), $label . ' : rien n\'est scellé');
            self::assertCount(1, $logger->records, $label);
            self::assertSame('critical', $logger->records[0][0], $label);
            self::assertSame($expected, $logger->records[0][2]['cause'], $label);
        }
    }

    /** G-7 : la rotation de bout en bout (A, puis B avec A retirée), et le retour à A refusé. */
    public function testRotationEndToEndThenBackToTheOldKeyIsRefused(): void
    {
        [$chain, $keyA] = $this->realChain(4);
        $keys = ['A' => $keyA, 'B' => self::KEY_B];
        $ids = array_map(static fn (Facture $f) => $f->getId(), $chain);
        $prints = array_map(static fn (Facture $f): string => $f->getEmpreinte(), $chain);
        $draftId = $this->draftId();
        $this->em()->clear();

        // A actif, chaîne de A : se prolonge. Rotation vers B (A retirée) : se prolonge.
        [$a] = $this->handlerFor('A', [], $keys);
        $a->sceller($this->facture($draftId));
        $this->em()->clear();
        [$b] = $this->handlerFor('B', ['A'], $keys);
        $draft = $this->facture($draftId);
        $b->sceller($draft);
        self::assertSame(SealKeyring::keyIdOf(self::KEY_B), $draft->getSealKeyId());

        // La chaîne porte maintenant des documents de B (réécriture des deux derniers) : B poursuit, A est refusée.
        foreach ([2, 3] as $i) {
            $this->persistKey($ids[$i], $prints[$i], 'B', $keys, $keyA);
        }
        $this->em()->clear();
        $b->sceller($this->facture($draftId));
        $this->em()->clear();
        [$backToA, $logger] = $this->handlerFor('A', ['B'], $keys);
        $refused = $this->facture($draftId);
        try {
            $backToA->sceller($refused);
            self::fail('Le retour à la clé A aurait dû être refusé.');
        } catch (SealingRefusedException $e) {
            self::assertSame(409, $e->getStatusCode());
        }
        self::assertSame(0, $refused->getNumeroSequence());
        self::assertCount(1, $logger->records);
        $this->em()->clear();
    }

    /** Un identifiant de chaîne nul (document sans exploitant) est un refus (409), jamais une exception brute. */
    public function testADocumentWithoutProfilIsRefusedNotAnException(): void
    {
        [, $keyA] = $this->realChain(1);
        [$handler] = $this->handlerFor('A', [], ['A' => $keyA]);
        $orphan = new Facture();
        try {
            $handler->sceller($orphan);
            self::fail('Un document sans exploitant ne peut pas être scellé.');
        } catch (SealingRefusedException $e) {
            self::assertSame(409, $e->getStatusCode());
        }
        self::assertSame(0, $orphan->getNumeroSequence());
        $this->em()->clear();
    }

    private function draftId(): string
    {
        [$client, $entete] = $this->adminSurA();
        $brouillon = $client->request('POST', '/api/factures', $entete + ['json' => $this->corpsFactureDirecte(999.0)])->toArray();

        return (string) $brouillon['id'];
    }

    private function facture(string $id): Facture
    {
        $facture = $this->em()->getRepository(Facture::class)->find(Uuid::fromString($id));
        self::assertNotNull($facture);

        return $facture;
    }

    private function persistKey(mixed $id, string $fingerprint, ?string $name, array $keys, string $keyA): void
    {
        $ring = new SealKeyring(self::VARIABLE, $name === null ? $keyA : $keys[$name]);
        $this->em()->getConnection()->executeStatement(
            'UPDATE facturation_facture SET seal_key_id = ?, signature = ? WHERE id = ?',
            [$name === null ? null : $ring->activeKeyId(), $name === null ? $ring->signWithoutId($fingerprint) : $ring->sign($fingerprint), Uuid::fromString((string) $id)->toBinary()],
        );
    }

    /**
     * @param list<string>          $retired
     * @param array<string, string> $keys
     *
     * @return array{0: ScellementFactureHandler, 1: AbstractLogger&object{records: list<array{string, string, array<mixed>}>}}
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

        return [$this->verifier($ring), $logger];
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
