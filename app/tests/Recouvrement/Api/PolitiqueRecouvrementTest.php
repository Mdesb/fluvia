<?php

declare(strict_types=1);

namespace App\Tests\Recouvrement\Api;

use App\Tests\Recouvrement\RecouvrementApiTestCase;

/**
 * Politique de recouvrement rattachée à l'établissement (pas à une verticale, RG-SOCLE-05) : 1 par
 * établissement, lisible/paramétrable via les permissions génériques `recouvrement.*`.
 */
final class PolitiqueRecouvrementTest extends RecouvrementApiTestCase
{
    public function testLaPolitiqueDeDemoEstRattacheeAUnEtablissementPasAUneVerticale(): void
    {
        [$client, $entete] = $this->adminSurA();
        $politique = $this->politiqueDemo();

        $client->request('GET', '/api/politique_recouvrements/' . $politique->getId(), $entete);
        self::assertResponseIsSuccessful();
        $donnees = $client->getResponse()->toArray();
        self::assertSame('apres_representation_echouee', $donnees['momentRefusAcces']);
        self::assertArrayNotHasKey('abonnement', $donnees);
    }

    public function testParametrerLaPolitiqueRequiertLaPermissionDediee(): void
    {
        [$client, $entete] = $this->adminSurA();
        $politique = $this->politiqueDemo();

        $client->request('PATCH', '/api/politique_recouvrements/' . $politique->getId(), [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => $entete['headers'] + ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['nbRepresentationsMax' => 2],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $client->getResponse()->toArray()['nbRepresentationsMax']);
    }
}
