<?php

declare(strict_types=1);

namespace App\Tests\Crm\Api;

use App\Tests\Crm\CrmApiTestCase;

/**
 * UI-5 — moyen de paiement préféré du client (`Client::$preferredPaymentMethodCode`) : référence
 * souple par code au référentiel `App\Compta\Entity\MoyenPaiement` (pas de FK cross-module, D2/D8).
 * Renseigné par l'exploitant depuis le back-office ; exposé en lecture ET écriture (même famille que
 * `email`/`telephone`).
 */
final class PreferredPaymentMethodTest extends CrmApiTestCase
{
    public function testCreationClientAvecMoyenPaiementPrefereEtLecture(): void
    {
        [$client, $entete] = $this->adminSurA();

        $cree = $client->request('POST', '/api/clients', $entete + [
            'json' => [
                'type' => 'physique',
                'nom' => 'Martin',
                'prenom' => 'Alice',
                'preferredPaymentMethodCode' => 'cb',
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame('cb', $cree['preferredPaymentMethodCode'], 'client:write — le champ est accepté à la création.');

        $relu = $client->request('GET', '/api/clients/' . $cree['id'], $entete)->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame('cb', $relu['preferredPaymentMethodCode'], 'client:read — le champ est exposé et persistant.');
    }

    public function testModificationDuMoyenPaiementPrefereViaPatch(): void
    {
        [$client, $entete] = $this->adminSurA();
        $clientId = $this->idPayeur();

        // Non renseigné sur la fixture. API Platform **omet** les valeurs null en sérialisation :
        // la clé est donc simplement absente (null-ou-absente), pas présente avec la valeur null.
        $avant = $client->request('GET', '/api/clients/' . $clientId, $entete)->toArray();
        self::assertNull($avant['preferredPaymentMethodCode'] ?? null);

        $client->request('PATCH', '/api/clients/' . $clientId, [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => $entete['headers'] + ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['preferredPaymentMethodCode' => 'especes'],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame('especes', $client->getResponse()->toArray()['preferredPaymentMethodCode']);

        $relu = $client->request('GET', '/api/clients/' . $clientId, $entete)->toArray();
        self::assertSame('especes', $relu['preferredPaymentMethodCode'], 'Persistant après relecture.');
    }
}
