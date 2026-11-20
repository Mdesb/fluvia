<?php

declare(strict_types=1);

namespace App\Tests\Platform\Api;

use App\DataFixtures\SocleFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\SocleApiTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * L'API NF525 DE L'ADMINISTRATEUR : CE QU'ELLE MONTRE, ET SURTOUT CE QU'ELLE NE MONTRE PAS.
 *
 * Chaque absence affirmée pose d'abord un témoin positif dans le même appel (une liste vide est
 * aussi ce que rendrait un cloisonnement cassé). Le détail technique est semé en base avec un
 * marqueur : on prouve qu'il existe côté base ET qu'il ne sort pas.
 */
final class Nf525AlertViewTest extends SocleApiTestCase
{
    private const MARKER = 'MARQUEUR-TECHNIQUE-9f3c';

    public function testAdminSeesOpenAndResolvedOfOwnEstablishmentOnly(): void
    {
        $client = static::createClient();
        $a = $this->establishmentId(SocleFixtures::ETAB_A_NOM);
        $b = $this->establishmentId(SocleFixtures::ETAB_B_NOM);
        $open = $this->alert('NF525-AAAAAAA1', 'open', [$a]);
        $resolved = $this->alert('NF525-AAAAAAA2', 'resolved', [$a]);
        $other = $this->alert('NF525-BBBBBBB1', 'open', [$b]);

        $ids = $this->listIds($client, $a);

        self::assertContains($open, $ids, 'Témoin : je dois voir mon alerte ouverte.');
        self::assertContains($resolved, $ids, 'Témoin : je dois voir mon alerte résolue.');
        self::assertNotContains($other, $ids);
        self::assertContains($other, $this->listIds($client, $b), 'Témoin : B voit la sienne.');
    }

    public function testItemOfAnotherEstablishmentIs404AndOwnIs200(): void
    {
        $client = static::createClient();
        $a = $this->establishmentId(SocleFixtures::ETAB_A_NOM);
        $b = $this->establishmentId(SocleFixtures::ETAB_B_NOM);
        $mine = $this->alert('NF525-AAAAAAA1', 'open', [$a]);
        $theirs = $this->alert('NF525-BBBBBBB1', 'open', [$b]);

        self::assertSame(200, $client->request('GET', '/api/nf525-alerts/'.$mine, $this->options($client, $a))->getStatusCode());
        self::assertSame(404, $client->request('GET', '/api/nf525-alerts/'.$theirs, $this->options($client, $a))->getStatusCode());
        self::assertSame(404, $client->request('GET', '/api/nf525-alerts/pas-un-uuid', $this->options($client, $a))->getStatusCode());
    }

    public function testExposesExactlyTheAllowedFieldsAndNeverTechnicalDetail(): void
    {
        $client = static::createClient();
        $a = $this->establishmentId(SocleFixtures::ETAB_A_NOM);
        $id = $this->alert('NF525-AAAAAAA1', 'open', [$a]);

        // Témoin positif : le marqueur est bien en base.
        self::assertSame(self::MARKER, $this->db()->fetchOne('SELECT technical_detail FROM platform_nf525_alert WHERE reference = ?', ['NF525-AAAAAAA1']));

        $list = $client->request('GET', '/api/nf525-alerts', $this->options($client, $a));
        $item = $client->request('GET', '/api/nf525-alerts/'.$id, $this->options($client, $a));

        foreach ([$list->getContent(), $item->getContent()] as $raw) {
            self::assertStringNotContainsString(self::MARKER, $raw);
            self::assertStringNotContainsString('sales:', $raw, 'La clé de chaîne ne sort pas.');
            self::assertStringNotContainsString('NOTE-ÉDITEUR', $raw);
        }

        $data = $item->toArray();
        foreach (['technicalDetail', 'note', 'noteAt', 'noteBy', 'detectionCount', 'origin', 'chainKey', 'pointOfSaleId', 'operatorProfileId', 'journalId'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $data);
        }
        $present = array_values(array_filter(array_keys($data), static fn (string $k): bool => !str_starts_with($k, '@')));
        $expected = ['id', 'reference', 'chainType', 'chainLabel', 'cause', 'causeLabel', 'status', 'firstEventAt', 'lastEventAt', 'refusalCount']; // resolvedAt : null, donc omise d'une alerte ouverte
        sort($present);
        sort($expected);
        self::assertSame($expected, $present);
        self::assertSame('NF525-AAAAAAA1', $data['reference']);
        self::assertSame('Clé de scellement inconnue', $data['causeLabel']);
        self::assertSame('unknown_key', $data['cause']);
        self::assertSame(3, $data['refusalCount']);

        $resolved = $this->alert('NF525-AAAAAAA9', 'resolved', [$a]);
        $resolvedData = $client->request('GET', '/api/nf525-alerts/'.$resolved, $this->options($client, $a))->toArray();
        self::assertArrayHasKey('resolvedAt', $resolvedData);
    }

    public function testAlertLinkedToTwoEstablishmentsIsVisibleFromBoth(): void
    {
        $client = static::createClient();
        $a = $this->establishmentId(SocleFixtures::ETAB_A_NOM);
        $b = $this->establishmentId(SocleFixtures::ETAB_B_NOM);
        $both = $this->alert('NF525-CCCCCCC1', 'open', [$a, $b]);

        self::assertContains($both, $this->listIds($client, $a));
        self::assertContains($both, $this->listIds($client, $b));
        self::assertSame(1, array_count_values($this->listIds($client, $a))[$both], 'Pas de doublon.');
    }

    public function testStatusFilterAndOrdering(): void
    {
        $client = static::createClient();
        $a = $this->establishmentId(SocleFixtures::ETAB_A_NOM);
        $resolvedRecent = $this->alert('NF525-DDDDDDD1', 'resolved', [$a], '2026-11-19 10:00:00');
        $openOld = $this->alert('NF525-DDDDDDD2', 'open', [$a], '2026-11-01 10:00:00');
        $openNew = $this->alert('NF525-DDDDDDD3', 'open', [$a], '2026-11-10 10:00:00');

        self::assertSame([$openNew, $openOld, $resolvedRecent], $this->listIds($client, $a));
        self::assertSame([$openNew, $openOld], $this->listIds($client, $a, ['status' => 'open']));
        self::assertSame([$resolvedRecent], $this->listIds($client, $a, ['status' => 'resolved']));
        self::assertSame([], $this->listIds($client, $a, ['status' => 'nimporte']));
    }

    public function testNoActiveEstablishmentGivesEmptyList(): void
    {
        $client = static::createClient();
        $a = $this->establishmentId(SocleFixtures::ETAB_A_NOM);
        $id = $this->alert('NF525-AAAAAAA1', 'open', [$a]);

        $options = ['auth_bearer' => $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP)];

        self::assertSame([], $this->members($client->request('GET', '/api/nf525-alerts', $options)->toArray()));
        self::assertSame(404, $client->request('GET', '/api/nf525-alerts/'.$id, $options)->getStatusCode());
        // Témoin : avec l'établissement, elle sort.
        self::assertContains($id, $this->listIds($client, $a));
    }

    public function testWritesAreNotAllowed(): void
    {
        $client = static::createClient();
        $a = $this->establishmentId(SocleFixtures::ETAB_A_NOM);
        $id = $this->alert('NF525-AAAAAAA1', 'open', [$a]);
        $options = $this->options($client, $a);

        self::assertSame(405, $client->request('POST', '/api/nf525-alerts', $options + ['json' => ['reference' => 'x']])->getStatusCode());
        self::assertSame(405, $client->request('PATCH', '/api/nf525-alerts/'.$id, ['headers' => ['Content-Type' => 'application/merge-patch+json'] + $options['headers']] + $options + ['json' => ['status' => 'resolved']])->getStatusCode());
        self::assertSame(405, $client->request('DELETE', '/api/nf525-alerts/'.$id, $options)->getStatusCode());
        self::assertSame('open', $this->db()->fetchOne('SELECT status FROM platform_nf525_alert WHERE reference = ?', ['NF525-AAAAAAA1']));
    }

    public function testReaderWithoutSecurityManageGets403AndAnonymousGets401(): void
    {
        $client = static::createClient();
        $a = $this->establishmentId(SocleFixtures::ETAB_A_NOM);
        $this->alert('NF525-AAAAAAA1', 'open', [$a]);

        $reader = [
            'auth_bearer' => $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP),
            'headers' => [ContexteEtablissement::HEADER => $a],
        ];
        self::assertSame(403, $client->request('GET', '/api/nf525-alerts', $reader)->getStatusCode());
        // Témoin : l'administrateur, lui, passe.
        self::assertSame(200, $client->request('GET', '/api/nf525-alerts', $this->options($client, $a))->getStatusCode());

        self::assertSame(401, $client->request('GET', '/api/nf525-alerts', ['headers' => [ContexteEtablissement::HEADER => $a]])->getStatusCode());
    }

    /** @return array<string, mixed> */
    private function options(object $client, string $establishmentId): array
    {
        return [
            'auth_bearer' => $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP),
            'headers' => [ContexteEtablissement::HEADER => $establishmentId],
        ];
    }

    /**
     * @param array<string, string> $query
     *
     * @return list<string>
     */
    private function listIds(object $client, string $establishmentId, array $query = []): array
    {
        $response = $client->request('GET', '/api/nf525-alerts', $this->options($client, $establishmentId) + ['query' => $query]);

        return array_map(static fn (array $m): string => $m['id'], $this->members($response->toArray()));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<array<string, mixed>>
     */
    private function members(array $body): array
    {
        return $body['member'] ?? $body['hydra:member'] ?? [];
    }

    /** @param list<string> $establishmentIds */
    private function alert(string $reference, string $status, array $establishmentIds, string $lastEventAt = '2026-11-18 10:00:00'): string
    {
        $id = Uuid::v4();
        $db = $this->db();
        $db->executeStatement(
            "INSERT INTO platform_nf525_alert (id, reference, chain_type, chain_key, chain_label, cause, origin, status, refusal_count, detection_count, first_event_at, last_event_at, technical_detail, note, resolved_at)
             VALUES (?, ?, 'sales', ?, 'Caisse 1', 'unknown_key', 'refusal', ?, 3, 7, '2026-10-01 10:00:00', ?, ?, 'NOTE-ÉDITEUR', ?)",
            [$id->toBinary(), $reference, 'sales:'.$id->toRfc4122(), $status, $lastEventAt, self::MARKER, $status === 'resolved' ? $lastEventAt : null],
        );
        foreach ($establishmentIds as $establishmentId) {
            $db->executeStatement(
                'INSERT INTO platform_nf525_alert_establishment (alert_id, establishment_id) VALUES (?, ?)',
                [$id->toBinary(), Uuid::fromString($establishmentId)->toBinary()],
            );
        }

        return $id->toRfc4122();
    }

    private function establishmentId(string $name): string
    {
        return $this->idEtablissement($name);
    }

    private function db(): Connection
    {
        /** @var Connection $db */
        $db = static::getContainer()->get('doctrine')->getConnection();

        return $db;
    }
}
