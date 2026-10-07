<?php

declare(strict_types=1);

namespace App\Tests\PublicApi\Api;

use App\DataFixtures\SocleFixtures;
use App\PublicApi\Entity\ApiCredential;
use App\PublicApi\Service\PartnerAccessManager;
use App\Tests\PublicApi\PublicApiTestCase;

/**
 * Une clé s'émet, s'utilise, se révoque — par l'administration éditeur, sans toucher à la base
 * (spec API partenaire v1, critère d'acceptation n°1).
 */
final class PartnerCredentialLifecycleTest extends PublicApiTestCase
{
    public function testUneCleSEmetSUtiliseEtSeRevoqueSansToucherLaBase(): void
    {
        $application = $this->createApplication();
        ['secret' => $secret, 'credentialId' => $credentialId] = $this->issueKey($application['id']);

        self::assertMatchesRegularExpression('/^flv_[0-9a-f]{64}$/', $secret);

        [$status, $me] = $this->me($secret);
        self::assertSame(200, $status);
        self::assertSame($application['id'], $me['application']['id']);
        self::assertSame([], $me['grants'], 'aucun établissement n’a encore consenti : la clé ne voit rien');

        [$client, $headers] = $this->admin();
        $client->request('POST', '/api/editor/partner-credentials/'.$credentialId.'/revoke', $headers);
        self::assertResponseIsSuccessful();

        [$status] = $this->me($secret);
        self::assertSame(401, $status, 'une clé révoquée ne passe plus');

        $this->em()->clear();
        $credential = $this->em()->getRepository(ApiCredential::class)->find($credentialId);
        self::assertNotNull($credential?->getRevokedAt());
        self::assertSame(SocleFixtures::ADMIN_EMAIL, $credential->getRevokedBy()?->getEmail());

        // Chaque geste de l'éditeur est rattaché à l'éditeur.
        $editor = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        self::assertSame($editor, $this->auditEstablishment(PartnerAccessManager::ACTION_APPLICATION_CREATED, $application['id']));
        self::assertSame($editor, $this->auditEstablishment(PartnerAccessManager::ACTION_CREDENTIAL_ISSUED, $credentialId));
        self::assertSame($editor, $this->auditEstablishment(PartnerAccessManager::ACTION_CREDENTIAL_REVOKED, $credentialId));
    }

    /** Le secret n'est montré qu'une fois : ni la liste, ni la réponse suivante ne le contiennent. */
    public function testLeSecretNEstJamaisRelisible(): void
    {
        $application = $this->createApplication();
        ['secret' => $secret, 'credentialId' => $credentialId] = $this->issueKey($application['id']);

        [$client, $headers] = $this->admin();
        $list = $client->request('GET', '/api/editor/partner-applications', $headers);
        self::assertResponseIsSuccessful();

        $raw = $list->getContent();
        self::assertStringNotContainsString($secret, $raw);
        self::assertStringNotContainsString(hash('sha256', $secret), $raw, 'l’empreinte ne sort pas non plus');

        $members = $list->toArray()['member'] ?? [];
        self::assertCount(1, $members);
        self::assertNull($members[0]['issuedSecret']);
        self::assertSame($credentialId, $members[0]['credentials'][0]['id']);
        self::assertSame(substr($secret, 0, 12), $members[0]['credentials'][0]['prefix']);
        self::assertSame('active', $members[0]['credentials'][0]['status']);
    }

    /**
     * Un compte qui n'est pas dans le tenant éditeur n'émet pas de clé, et n'apprend pas que l'écran
     * existe : 404. Témoin : la MÊME requête passe pour l'éditeur — seul le tenant change.
     */
    public function testUnNonEditeurNePeutPasEmettreDeCle(): void
    {
        $application = $this->createApplication();
        $uri = '/api/editor/partner-applications/'.$application['id'].'/credentials';

        [$client, $headers] = $this->admin(SocleFixtures::ETAB_B_NOM);
        $refused = $client->request('POST', $uri, $headers + ['json' => new \stdClass()]);
        self::assertSame(404, $refused->getStatusCode(), (string) $refused->getContent(false));
        self::assertCount(0, $this->em()->getRepository(ApiCredential::class)->findAll());

        [$client, $headers] = $this->admin();
        $client->request('POST', $uri, $headers + ['json' => new \stdClass()]);
        self::assertResponseIsSuccessful('témoin : la même requête passe depuis l’éditeur');
    }

    public function testUneCleExpireeEstRefusee(): void
    {
        $application = $this->createApplication();
        ['secret' => $secret, 'credentialId' => $credentialId] = $this->issueKey($application['id']);
        self::assertSame(200, $this->me($secret)[0], 'témoin : la clé passe avant son terme');

        $credential = $this->em()->getRepository(ApiCredential::class)->find($credentialId);
        $credential?->setExpiresAt(new \DateTimeImmutable('-1 minute'));
        $this->em()->flush();

        self::assertSame(401, $this->me($secret)[0]);
    }

    public function testUneApplicationDesactiveeNePassePlus(): void
    {
        $application = $this->createApplication();
        ['secret' => $secret] = $this->issueKey($application['id']);
        self::assertSame(200, $this->me($secret)[0], 'témoin : la clé passe tant que l’application est active');

        [$client, $headers] = $this->admin();
        $client->request('POST', '/api/editor/partner-applications/'.$application['id'].'/deactivate', $headers);
        self::assertResponseIsSuccessful();

        self::assertSame(401, $this->me($secret)[0]);
        self::assertSame(
            $this->idEtablissement(SocleFixtures::ETAB_A_NOM),
            $this->auditEstablishment(PartnerAccessManager::ACTION_APPLICATION_DEACTIVATED, $application['id']),
        );

        // Et l'éditeur ne peut plus lui émettre de clé : elle serait refusée à chaque appel.
        [$client, $headers] = $this->admin();
        $refused = $client->request('POST', '/api/editor/partner-applications/'.$application['id'].'/credentials', $headers + ['json' => new \stdClass()]);
        self::assertSame(422, $refused->getStatusCode());
    }

    /** `lastUsedAt` s'écrit à l'usage, mais au plus une fois par minute. */
    public function testLastUsedAtEstEcritAuPlusUneFoisParMinute(): void
    {
        $application = $this->createApplication();
        ['secret' => $secret, 'credentialId' => $credentialId] = $this->issueKey($application['id']);

        $this->me($secret);
        $first = $this->lastUsedAt($credentialId);
        self::assertNotNull($first, 'le premier usage est noté');

        // Dans la minute : pas de réécriture. On recule la valeur d'une seconde pour la distinguer
        // d'une réécriture qui tomberait dans la même seconde.
        $this->setLastUsedAt($credentialId, $first->modify('-1 second'));
        $this->me($secret);
        self::assertEquals($first->modify('-1 second'), $this->lastUsedAt($credentialId));

        // Au-delà : réécrit.
        $this->setLastUsedAt($credentialId, new \DateTimeImmutable('-2 minutes'));
        $this->me($secret);
        self::assertGreaterThan(new \DateTimeImmutable('-1 minute'), $this->lastUsedAt($credentialId));
    }

    private function lastUsedAt(string $credentialId): ?\DateTimeImmutable
    {
        $this->em()->clear();

        return $this->em()->getRepository(ApiCredential::class)->find($credentialId)?->getLastUsedAt();
    }

    private function setLastUsedAt(string $credentialId, \DateTimeImmutable $at): void
    {
        $this->em()->getRepository(ApiCredential::class)->find($credentialId)?->setLastUsedAt($at);
        $this->em()->flush();
    }
}
