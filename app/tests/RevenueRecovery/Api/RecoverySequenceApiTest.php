<?php

declare(strict_types=1);

namespace App\Tests\RevenueRecovery\Api;

use App\DataFixtures\SocleFixtures;
use App\Tests\RevenueRecovery\RevenueRecoveryApiTestCase;

/**
 * `POST /revenue-recovery/sequences` (US-RR-01, RG-RR-01, plan-revenue-recovery.md §2/T4).
 */
final class RecoverySequenceApiTest extends RevenueRecoveryApiTestCase
{
    /** CA-1 US-RR-01 : séquence à deux étapes (J+1, J+3), enregistrée pour l'établissement du configurateur. */
    public function testCreationSequenceCartAbandonedDeuxEtapes(): void
    {
        [$client, $entete] = $this->userWithPermissions(SocleFixtures::ETAB_A_NOM, ['read', 'configure'], 'rr-config-' . uniqid('', true));

        $client->request('POST', '/api/revenue-recovery/sequences', $entete + [
            'json' => [
                'triggerType' => 'cart_abandoned',
                'active' => true,
                'maxAttempts' => 3,
                'steps' => [
                    ['delayDays' => 1, 'channel' => 'email', 'templateCode' => 'panier_j1'],
                    ['delayDays' => 3, 'channel' => 'email', 'templateCode' => 'panier_j3'],
                ],
            ],
        ]);
        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent(false));

        $sequence = $client->getResponse()->toArray();
        self::assertSame('cart_abandoned', $sequence['triggerType']);
        self::assertTrue($sequence['active']);
        self::assertCount(2, $sequence['steps']);

        $reponse = $client->request('GET', '/api/revenue-recovery/sequences', $entete)->toArray();
        $membres = $reponse['member'] ?? $reponse['hydra:member'] ?? [];
        self::assertCount(1, $membres, 'La séquence n\'est visible que sur son propre établissement (RG-RR-01).');
    }

    /** RG-RR-01 : une séquence par (établissement, déclencheur) — la seconde création est refusée 409. */
    public function testUniciteSequenceParEtablissementEtDeclencheurRefuse409(): void
    {
        [$client, $entete] = $this->userWithPermissions(SocleFixtures::ETAB_A_NOM, ['read', 'configure'], 'rr-config-' . uniqid('', true));

        $corps = [
            'json' => [
                'triggerType' => 'invoice_overdue',
                'active' => false,
                'maxAttempts' => 2,
                'steps' => [['delayDays' => 2, 'channel' => 'email', 'templateCode' => 'facture_j2']],
            ],
        ];

        $client->request('POST', '/api/revenue-recovery/sequences', $entete + $corps);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/revenue-recovery/sequences', $entete + $corps);
        self::assertResponseStatusCodeSame(409, (string) $client->getResponse()->getContent(false));
    }

    public function testCreationSansPermissionConfigureRefusee403(): void
    {
        [$client, $entete] = $this->userWithPermissions(SocleFixtures::ETAB_A_NOM, ['read'], 'rr-reader-' . uniqid('', true));

        $client->request('POST', '/api/revenue-recovery/sequences', $entete + [
            'json' => [
                'triggerType' => 'booking_no_show',
                'active' => true,
                'maxAttempts' => 1,
                'steps' => [['delayDays' => 1, 'channel' => 'email', 'templateCode' => 'no_show']],
            ],
        ]);
        self::assertResponseStatusCodeSame(403);
    }
}
