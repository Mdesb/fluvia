<?php

declare(strict_types=1);

namespace App\Tests\Crm\Api;

use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Client;
use App\Crm\Service\ConsentementResolver;
use App\Tests\Crm\CrmApiTestCase;

/**
 * US-L5-09, RG-M4-07 : consentements par canal.
 */
final class ConsentementTest extends CrmApiTestCase
{
    /** CA-18 — Révocation : état `refuse`, horodaté avec sa source, historique conservé (append-only). */
    public function testCa18RevocationConsentementHistoriqueConserve(): void
    {
        [$client, $entete] = $this->adminSurA();
        $payeurId = $this->idPayeur();

        $accord = $client->request('POST', '/api/clients/' . $payeurId . '/consentements', $entete + [
            'json' => ['canal' => 'email', 'etat' => 'accorde', 'source' => 'guichet'],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame('accorde', $accord['etat']);

        $revocation = $client->request('POST', '/api/clients/' . $payeurId . '/consentements', $entete + [
            'json' => ['canal' => 'email', 'etat' => 'refuse', 'source' => 'espace_client'],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame('refuse', $revocation['etat']);
        self::assertSame('espace_client', $revocation['source']);
        self::assertNotSame($accord['id'], $revocation['id'], 'CA-18 : append-only, nouvelle ligne insérée.');

        // Historique conservé : les deux lignes existent toujours (filtrage manuel côté test pour ne
        // pas dépendre de la résolution IRI/UUID brut du SearchFilter sur une association).
        $historique = $client->request('GET', '/api/consentements', $entete)->toArray();
        // En JSON-LD, une association vers une autre ressource est normalisée en objet
        // { "@id", "@type", "id" }, pas en simple chaîne IRI.
        $pourPayeurEmail = array_filter(
            $historique['member'] ?? [],
            static fn (array $c): bool => ($c['client']['id'] ?? null) === $payeurId && ($c['canal'] ?? null) === 'email',
        );
        self::assertGreaterThanOrEqual(2, \count($pourPayeurEmail));
    }

    /** CA-16, RG-M4-07 — Un client sans consentement accordé sur SMS est exclu d'une campagne ciblant ce canal. */
    public function testCa16ClientSansConsentementSmsExcluDeExport(): void
    {
        $resolver = static::getContainer()->get(ConsentementResolver::class);

        $payeur = $this->entite(Client::class, ['email' => CrmFixtures::PAYEUR_EMAIL]);
        self::assertFalse(
            $resolver->estExploitable($payeur, \App\Crm\Enum\CanalConsentement::Sms),
            'CA-16 : aucun consentement SMS -> non exploitable, exclu de tout envoi/export.',
        );
    }
}
