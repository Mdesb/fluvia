<?php

declare(strict_types=1);

namespace App\Tests\Crm\Api;

use App\Tests\Crm\CrmApiTestCase;

/**
 * RG-SOCLE-05 (spécificité Groupe de M4, §6 plan-crm.md) : un utilisateur voit un client dès qu'il a
 * une affectation dans le **groupe** du client, quel que soit l'établissement précis ; hors du groupe,
 * la fiche est invisible/inaccessible.
 */
final class CloisonnementTest extends CrmApiTestCase
{
    public function testClientDuGroupeAInvisiblePourUtilisateurDuGroupeB(): void
    {
        [$clientA, $enteteA] = $this->adminSurA();
        $payeurId = $this->idPayeur();

        // L'administrateur (groupe A) voit bien le client.
        $clientA->request('GET', '/api/clients/' . $payeurId, $enteteA);
        self::assertResponseIsSuccessful();

        // Un utilisateur affecté uniquement au groupe B ne le voit pas (item + collection).
        [$clientB, $enteteB] = $this->agentSurGroupeB();
        $reponseItem = $clientB->request('GET', '/api/clients/' . $payeurId, $enteteB);
        self::assertSame(404, $reponseItem->getStatusCode(), 'Cloisonnement Groupe : client hors périmètre -> 404 (introuvable).');

        $collection = $clientB->request('GET', '/api/clients', $enteteB)->toArray();
        $ids = array_map(static fn (array $c): string => $c['id'] ?? '', $collection['member'] ?? []);
        self::assertNotContains($payeurId, $ids, 'Cloisonnement Groupe : client hors périmètre absent de la collection.');
    }
}
