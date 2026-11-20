<?php

declare(strict_types=1);

namespace App\Tests\Platform\Api;

use App\DataFixtures\SocleFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\SocleApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261120100000;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

require_once \dirname(__DIR__, 3).'/migrations/Version20261120100000.php';

/**
 * L'API NF525 DE L'ÉDITEUR : tout voir, poser une note, et rien d'autre.
 *
 * L'éditeur est l'établissement A (comme `SupportAccessApiTest`). Chaque refus est encadré d'un témoin
 * positif : le même geste, la même charge, accepté pour l'éditeur ; seul le QUI change.
 */
final class EditorNf525AlertTest extends SocleApiTestCase
{
    private const MARKER = '<script>alert(1)</script> MARQUEUR-TECHNIQUE-9f3c';

    protected function setUp(): void
    {
        parent::setUp();
        $id = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $_ENV['EDITOR_TENANT_ID'] = $id;
        $_SERVER['EDITOR_TENANT_ID'] = $id;
    }

    public function testEditorSeesAllAlertsOfAllEstablishmentsWithTechnicalDetail(): void
    {
        $client = static::createClient();
        $a = $this->establishmentId(SocleFixtures::ETAB_A_NOM);
        $b = $this->establishmentId(SocleFixtures::ETAB_B_NOM);
        $one = $this->alert('NF525-AAAAAAA1', 'open', [$a]);
        $two = $this->alert('NF525-BBBBBBB1', 'resolved', [$b]);
        $three = $this->alert('NF525-CCCCCCC1', 'open', [$a, $b]);

        $list = $client->request('GET', '/api/editor/nf525-alerts', $this->editor($client))->toArray();
        $members = $list['member'] ?? $list['hydra:member'] ?? [];
        $byId = [];
        foreach ($members as $m) {
            $byId[$m['id']] = $m;
        }

        foreach ([$one, $two, $three] as $id) {
            self::assertArrayHasKey($id, $byId);
        }
        self::assertSame(self::MARKER, $byId[$one]['technicalDetail']);
        self::assertSame(7, $byId[$one]['detectionCount']);
        self::assertSame('refusal', $byId[$one]['origin']);
        self::assertSame('Clé de scellement inconnue', $byId[$one]['causeLabel']);
        self::assertSame([SocleFixtures::ETAB_B_NOM, SocleFixtures::ETAB_A_NOM], $byId[$three]['establishmentNames']);
        self::assertSame([SocleFixtures::ETAB_B_NOM], $byId[$two]['establishmentNames']);

        $item = $client->request('GET', '/api/editor/nf525-alerts/'.$two, $this->editor($client))->toArray();
        self::assertSame(self::MARKER, $item['technicalDetail']);

        $open = $client->request('GET', '/api/editor/nf525-alerts', $this->editor($client) + ['query' => ['status' => 'open']])->toArray();
        $ids = array_column($open['member'] ?? $open['hydra:member'] ?? [], 'id');
        self::assertContains($one, $ids);
        self::assertNotContains($two, $ids, 'Le filtre status écarte les résolues.');
    }

    public function testTechnicalDetailIsReturnedAsPlainTextAsIs(): void
    {
        $client = static::createClient();
        $id = $this->alert('NF525-AAAAAAA1', 'open', [$this->establishmentId(SocleFixtures::ETAB_B_NOM)]);

        $response = $client->request('GET', '/api/editor/nf525-alerts/'.$id, $this->editor($client));

        self::assertStringContainsString('json', (string) $response->getHeaders()['content-type'][0]);
        self::assertSame(self::MARKER, $response->toArray()['technicalDetail'], 'Ni échappé ni nettoyé : le frontal l\'affiche comme du texte.');
    }

    public function testNoteIsSetThenReadBackReplacedAndCleared(): void
    {
        $client = static::createClient();
        $id = $this->alert('NF525-AAAAAAA1', 'open', [$this->establishmentId(SocleFixtures::ETAB_B_NOM)]);

        $posted = $client->request('POST', '/api/editor/nf525-alerts/'.$id.'/note', $this->editor($client) + ['json' => ['note' => '  Clé réimportée, à surveiller.  ']]);
        self::assertSame(200, $posted->getStatusCode(), $posted->getContent(false));
        self::assertSame('Clé réimportée, à surveiller.', $posted->toArray()['note']);

        $read = $client->request('GET', '/api/editor/nf525-alerts/'.$id, $this->editor($client))->toArray();
        self::assertSame('Clé réimportée, à surveiller.', $read['note']);
        self::assertSame(SocleFixtures::ADMIN_EMAIL, $read['noteBy']);
        self::assertNotEmpty($read['noteAt']);

        $client->request('POST', '/api/editor/nf525-alerts/'.$id.'/note', $this->editor($client) + ['json' => ['note' => 'Seconde version.']]);
        self::assertSame('Seconde version.', $client->request('GET', '/api/editor/nf525-alerts/'.$id, $this->editor($client))->toArray()['note']);

        $cleared = $client->request('POST', '/api/editor/nf525-alerts/'.$id.'/note', $this->editor($client) + ['json' => ['note' => '']]);
        self::assertSame(200, $cleared->getStatusCode());
        $after = $client->request('GET', '/api/editor/nf525-alerts/'.$id, $this->editor($client))->toArray();
        self::assertArrayNotHasKey('note', $after, 'Une note effacée est omise (null).');
        self::assertNull($this->db()->fetchOne('SELECT note FROM platform_nf525_alert WHERE id = ?', [Uuid::fromString($id)->toBinary()]) ?: null);
        self::assertNull($this->db()->fetchOne('SELECT note_by FROM platform_nf525_alert WHERE id = ?', [Uuid::fromString($id)->toBinary()]) ?: null);
    }

    public function testNoteOverTheLimitIs422AndWritesNothing(): void
    {
        $client = static::createClient();
        $id = $this->alert('NF525-AAAAAAA1', 'open', [$this->establishmentId(SocleFixtures::ETAB_B_NOM)]);
        $url = '/api/editor/nf525-alerts/'.$id.'/note';

        // Témoin : 2000 caractères exactement passent (et comptés en caractères, pas en octets).
        $ok = $client->request('POST', $url, $this->editor($client) + ['json' => ['note' => str_repeat('é', 2000)]]);
        self::assertSame(200, $ok->getStatusCode(), $ok->getContent(false));
        $audits = $this->auditCount();

        $refused = $client->request('POST', $url, $this->editor($client) + ['json' => ['note' => str_repeat('é', 2001)]]);
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(str_repeat('é', 2000), $this->storedNote($id), 'La note précédente est intacte.');
        self::assertSame($audits, $this->auditCount(), 'Aucune entrée d\'audit pour un refus.');

        self::assertSame(422, $client->request('POST', $url, $this->editor($client) + ['json' => ['note' => 12]])->getStatusCode());
        self::assertSame(422, $client->request('POST', $url, $this->editor($client) + ['json' => new \stdClass()])->getStatusCode());
    }

    public function testNoteLeavesAnAuditEntry(): void
    {
        $client = static::createClient();
        $id = $this->alert('NF525-AAAAAAA1', 'open', [$this->establishmentId(SocleFixtures::ETAB_B_NOM)]);
        $before = $this->auditCount();

        $client->request('POST', '/api/editor/nf525-alerts/'.$id.'/note', $this->editor($client) + ['json' => ['note' => 'Secret technique à ne pas copier.']]);

        self::assertSame($before + 1, $this->auditCount());
        $row = $this->db()->fetchAssociative("SELECT * FROM audit_entree WHERE action = 'nf525.alerte.note'");
        self::assertSame($id, $row['cible_id']);
        self::assertSame(SocleFixtures::ADMIN_EMAIL, $row['auteur']);
        self::assertStringContainsString('NF525-AAAAAAA1', (string) $row['valeur_apres']);
        self::assertStringNotContainsString('Secret technique', (string) $row['valeur_apres'], 'Le texte de la note n\'est pas recopié.');
    }

    public function testNoteChangesNeitherStatusNorCounters(): void
    {
        $client = static::createClient();
        $id = $this->alert('NF525-AAAAAAA1', 'open', [$this->establishmentId(SocleFixtures::ETAB_B_NOM)]);
        $sql = 'SELECT status, refusal_count, detection_count, resolved_at, last_event_at, origin, technical_detail FROM platform_nf525_alert WHERE id = ?';
        $before = $this->db()->fetchAssociative($sql, [Uuid::fromString($id)->toBinary()]);
        self::assertSame('open', $before['status']);

        $client->request('POST', '/api/editor/nf525-alerts/'.$id.'/note', $this->editor($client) + ['json' => ['note' => 'Note.']]);
        self::assertSame('Note.', $this->storedNote($id), 'Témoin : l\'écriture a bien eu lieu.');
        self::assertSame($before, $this->db()->fetchAssociative($sql, [Uuid::fromString($id)->toBinary()]));

        $client->request('POST', '/api/editor/nf525-alerts/'.$id.'/note', $this->editor($client) + ['json' => ['note' => '']]);
        self::assertSame($before, $this->db()->fetchAssociative($sql, [Uuid::fromString($id)->toBinary()]));
    }

    public function testEstablishmentAdminGets404NeverForbiddenOnEditorRoutes(): void
    {
        $client = static::createClient();
        $b = $this->establishmentId(SocleFixtures::ETAB_B_NOM);
        $id = $this->alert('NF525-AAAAAAA1', 'open', [$b]);
        $asClient = [
            'auth_bearer' => $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP),
            'headers' => [ContexteEtablissement::HEADER => $b],
        ];

        // Témoin : même compte, même charge, établissement actif = éditeur -> 200.
        self::assertSame(200, $client->request('GET', '/api/editor/nf525-alerts', $this->editor($client))->getStatusCode());
        self::assertSame(200, $client->request('POST', '/api/editor/nf525-alerts/'.$id.'/note', $this->editor($client) + ['json' => ['note' => 'x']])->getStatusCode());

        self::assertSame(404, $client->request('GET', '/api/editor/nf525-alerts', $asClient)->getStatusCode());
        self::assertSame(404, $client->request('GET', '/api/editor/nf525-alerts/'.$id, $asClient)->getStatusCode());
        $post = $client->request('POST', '/api/editor/nf525-alerts/'.$id.'/note', $asClient + ['json' => ['note' => 'pirate']]);
        self::assertSame(404, $post->getStatusCode());
        self::assertSame('x', $this->storedNote($id), 'Rien n\'a été écrit.');
    }

    public function testEditorAccountWithoutTheRightGets403AndWithItGets200(): void
    {
        $client = static::createClient();
        $id = $this->alert('NF525-AAAAAAA1', 'open', [$this->establishmentId(SocleFixtures::ETAB_B_NOM)]);
        $options = [
            'auth_bearer' => $this->jeton($client, SocleFixtures::ASSISTANCE_EDITEUR_EMAIL, SocleFixtures::ASSISTANCE_EDITEUR_MDP),
            'headers' => [ContexteEtablissement::HEADER => $this->establishmentId(SocleFixtures::ETAB_A_NOM)],
        ];

        // Témoin : l'assistance porte le droit (décision du 20/11) -> 200 sur les trois routes.
        self::assertSame(200, $client->request('GET', '/api/editor/nf525-alerts', $options)->getStatusCode());
        self::assertSame(200, $client->request('GET', '/api/editor/nf525-alerts/'.$id, $options)->getStatusCode());
        self::assertSame(200, $client->request('POST', '/api/editor/nf525-alerts/'.$id.'/note', $options + ['json' => ['note' => 'ok']])->getStatusCode());

        // On retire le droit au rôle, rien d'autre ne change.
        $this->db()->executeStatement(
            "DELETE rp FROM sec_role_permission rp JOIN sec_permission p ON p.id = rp.permission_id JOIN sec_role r ON r.id = rp.role_id
              WHERE p.module = 'editor' AND p.action = 'nf525_alerts' AND r.nom = 'Éditeur — Assistance'",
        );

        self::assertSame(403, $client->request('GET', '/api/editor/nf525-alerts', $options)->getStatusCode());
        self::assertSame(403, $client->request('GET', '/api/editor/nf525-alerts/'.$id, $options)->getStatusCode());
        self::assertSame(403, $client->request('POST', '/api/editor/nf525-alerts/'.$id.'/note', $options + ['json' => ['note' => 'refusé']])->getStatusCode());
        self::assertSame('ok', $this->storedNote($id));
    }

    public function testMigrationReplayedTwiceAddsNoDuplicate(): void
    {
        // Le harnais a semé la permission par les fixtures : on repart d'une base sans elle.
        $this->db()->executeStatement("DELETE rp FROM sec_role_permission rp JOIN sec_permission p ON p.id = rp.permission_id WHERE p.module = 'editor' AND p.action = 'nf525_alerts'");
        $this->db()->executeStatement("DELETE FROM sec_permission WHERE module = 'editor' AND action = 'nf525_alerts'");

        $this->play('up');
        $this->play('up');

        self::assertSame(1, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM sec_permission WHERE module = 'editor' AND action = 'nf525_alerts'"));
        $roles = $this->db()->fetchFirstColumn(
            "SELECT r.nom FROM sec_role_permission rp JOIN sec_permission p ON p.id = rp.permission_id JOIN sec_role r ON r.id = rp.role_id
              WHERE p.module = 'editor' AND p.action = 'nf525_alerts' ORDER BY r.nom",
        );
        $expected = ['Administrateur groupe', 'Éditeur — Assistance', 'Éditeur — Direction'];
        sort($expected);
        self::assertSame($expected, $roles, 'Trois rattachements, chacun une seule fois.');

        // Après un chargement de fixtures (qui cherchent avant de créer) : toujours rien en double.
        $this->play('up');
        self::assertSame(1, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM sec_permission WHERE module = 'editor' AND action = 'nf525_alerts'"));

        $this->play('down');
        self::assertSame(0, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM sec_permission WHERE module = 'editor' AND action = 'nf525_alerts'"));
    }

    private function play(string $direction): void
    {
        $migration = new Version20261120100000($this->db(), new NullLogger());
        $migration->{$direction}(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->db()->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /** @return array<string, mixed> L'administrateur du jeu de données, établissement actif = l'éditeur (A). */
    private function editor(object $client): array
    {
        return [
            'auth_bearer' => $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP),
            'headers' => [ContexteEtablissement::HEADER => $this->establishmentId(SocleFixtures::ETAB_A_NOM)],
        ];
    }

    private function storedNote(string $id): ?string
    {
        $note = $this->db()->fetchOne('SELECT note FROM platform_nf525_alert WHERE id = ?', [Uuid::fromString($id)->toBinary()]);

        return false === $note ? null : $note;
    }

    private function auditCount(): int
    {
        return (int) $this->db()->fetchOne("SELECT COUNT(*) FROM audit_entree WHERE action = 'nf525.alerte.note'");
    }

    /** @param list<string> $establishmentIds */
    private function alert(string $reference, string $status, array $establishmentIds): string
    {
        $id = Uuid::v4();
        $db = $this->db();
        $db->executeStatement(
            "INSERT INTO platform_nf525_alert (id, reference, chain_type, chain_key, chain_label, cause, origin, status, refusal_count, detection_count, first_event_at, last_event_at, technical_detail, note, resolved_at)
             VALUES (?, ?, 'sales', ?, 'Caisse 1', 'unknown_key', 'refusal', ?, 3, 7, '2026-10-01 10:00:00', '2026-11-18 10:00:00', ?, NULL, ?)",
            [$id->toBinary(), $reference, 'sales:'.$id->toRfc4122(), $status, self::MARKER, 'resolved' === $status ? '2026-11-18 10:00:00' : null],
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
