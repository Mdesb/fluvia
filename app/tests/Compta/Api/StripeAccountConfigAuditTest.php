<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\Audit\Entity\EntreeAudit;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\StripeAccountConfig;
use App\Compta\Enum\TypeExploitant;
use App\DataFixtures\SocleFixtures;
use App\Tests\Compta\ComptaApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * G-8 : la pose, le remplacement et le retrait d'une clé Stripe laissent une entrée d'audit (qui, quand,
 * quel établissement, quelle valeur a changé) SANS jamais contenir la clé, le secret ni leur version chiffrée.
 *
 * Le cloisonnement du journal entre clients repose sur l'établissement porté par l'entrée (assertion ci-dessous :
 * celui du profil) et sur `AuditTenantIsolationTest` (qui exige aussi que la classe ait décidé de son établissement).
 */
final class StripeAccountConfigAuditTest extends ComptaApiTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    /** @return list<EntreeAudit> */
    private function entries(): array
    {
        $this->em()->clear();

        return $this->em()->getRepository(EntreeAudit::class)->findBy(['cibleType' => StripeAccountConfig::class], ['dateHeure' => 'ASC', 'id' => 'ASC']);
    }

    /** @param array<string, mixed> $entete @param array<string, mixed> $json */
    private function patch(\ApiPlatform\Symfony\Bundle\Test\Client $client, array $entete, string $id, array $json): void
    {
        $client->request('PATCH', '/api/stripe_account_configs/' . $id, [
            'headers' => $entete['headers'] + ['Content-Type' => 'application/merge-patch+json'],
            'json' => $json,
        ] + $entete);
        self::assertResponseIsSuccessful();
    }

    public function testCreateReplaceAndRemoveAreAuditedWithoutAnySecret(): void
    {
        $profile = $this->profilExploitant()->setType(TypeExploitant::GroupePrive);
        $this->em()->flush();
        $profileId = (string) $profile->getId();
        $key = 'sk_test_' . bin2hex(random_bytes(12));
        $secret = 'whsec_' . bin2hex(random_bytes(12));
        $newSecret = 'whsec_' . bin2hex(random_bytes(12));
        $newKey = 'sk_live_' . bin2hex(random_bytes(12));
        [$client, $entete, $idA] = $this->adminSurA();

        $id = $client->request('POST', '/api/stripe_account_configs', $entete + ['json' => [
            'businessProfile' => '/api/profil_exploitants/' . $profileId,
            'secretKeyPlain' => $key,
            'webhookSecretPlain' => $secret,
        ]])->toArray()['id'];
        $this->patch($client, $entete, $id, ['webhookSecretPlain' => $newSecret]);
        $this->patch($client, $entete, $id, ['secretKeyPlain' => $newKey]);
        $client->request('DELETE', '/api/stripe_account_configs/' . $id, $entete);
        self::assertResponseStatusCodeSame(204);

        // Les valeurs chiffrées lues avant suppression ne sont plus en base : on prouve l'absence par le
        // contenu complet de chaque entrée, et le témoin positif est la présence des champs non secrets.
        $entries = $this->entries();
        $actions = array_map(static fn (EntreeAudit $e) => $e->getAction(), $entries);
        sort($actions);
        self::assertSame(['creation', 'modification', 'modification', 'suppression'], $actions);

        foreach ($entries as $entry) {
            self::assertSame(SocleFixtures::ADMIN_EMAIL, $entry->getAuteur());
            self::assertSame($id, $entry->getCibleId());
            self::assertSame($idA, (string) $entry->getEtablissement(), 'L\'entrée porte l\'établissement du profil.');
            $dump = json_encode([$entry->getValeurAvant(), $entry->getValeurApres()], JSON_THROW_ON_ERROR);
            foreach ([$key, $secret, $newSecret, $newKey, 'Plain', 'Encrypted', 'encrypted'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $dump);
            }
        }

        // L'horodatage a la seconde ne donne pas l'ordre : on reconnaît chaque entrée à son action et à son contenu.
        $byAction = static fn (string $action): array => array_values(array_filter($entries, static fn (EntreeAudit $e) => $e->getAction() === $action));
        $creation = $byAction('creation')[0];
        $removal = $byAction('suppression')[0];
        $modifications = $byAction('modification');
        $webhookChange = $modifications[array_key_first(array_filter($modifications, static fn (EntreeAudit $e) => !isset($e->getValeurApres()['keyMode'])))];
        $keyChange = $modifications[array_key_first(array_filter($modifications, static fn (EntreeAudit $e) => isset($e->getValeurApres()['keyMode'])))];
        self::assertSame('test', $creation->getValeurApres()['keyMode'] ?? null, 'Témoin positif : l\'instantané porte les champs non secrets.');
        self::assertNull($creation->getValeurAvant());

        // Remplacement du seul secret de webhook : ni la clé ni son mode ne bougent dans l'instantané.
        self::assertArrayHasKey('webhookSecretUpdatedAt', $webhookChange->getValeurApres());
        self::assertArrayNotHasKey('keyUpdatedAt', $webhookChange->getValeurApres());
        self::assertArrayNotHasKey('keyMode', $webhookChange->getValeurApres());

        // Remplacement de la seule clé (test -> live) : la date de la clé et le mode changent.
        self::assertSame('test', $keyChange->getValeurAvant()['keyMode'] ?? null);
        self::assertSame('live', $keyChange->getValeurApres()['keyMode'] ?? null);
        self::assertArrayHasKey('keyUpdatedAt', $keyChange->getValeurApres());
        self::assertArrayNotHasKey('webhookSecretUpdatedAt', $keyChange->getValeurApres());

        self::assertNull($removal->getValeurApres());
        self::assertSame('live', $removal->getValeurAvant()['keyMode'] ?? null);
    }
}
