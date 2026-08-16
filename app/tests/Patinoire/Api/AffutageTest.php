<?php

declare(strict_types=1);

namespace App\Tests\Patinoire\Api;

use App\Tests\Patinoire\PatinoireApiTestCase;

/** Affûtage : prestation client vs maintenance du parc (US-PATIN-06/07, RG-PAT-06, décision actée). */
final class AffutageTest extends PatinoireApiTestCase
{
    public function testPrestationClientSansImpactParc(): void
    {
        [$client, $entete, $idA] = $this->technicienSurA();
        $client->disableReboot();

        $idParc = $this->idParcPatins(43);
        $client->request('GET', '/api/patinoire_parc_patins/' . $idParc, $entete);
        $avant = $client->getResponse()->toArray();

        [, $enteteTech] = [null, $entete];
        $client->request('GET', '/me', $entete);
        $idTechnicien = $client->getResponse()->toArray()['id'];

        $client->request('POST', '/api/patinoire/affutages', $entete + [
            'json' => [
                'type' => 'prestation_client',
                'technicien' => '/api/utilisateurs/' . $idTechnicien,
                'etablissement' => '/api/etablissements/' . $idA,
            ],
        ]);
        self::assertResponseIsSuccessful();
        $affutage = $client->getResponse()->toArray();
        self::assertSame('prestation_client', $affutage['type']);
        self::assertSame('en_cours', $affutage['statut']);

        $client->request('GET', '/api/patinoire_parc_patins/' . $idParc, $entete);
        $apres = $client->getResponse()->toArray();
        self::assertSame($avant['quantiteDisponible'], $apres['quantiteDisponible'], 'CA-6 : aucun impact sur le parc de location.');

        $client->request('POST', '/api/patinoire/affutages/' . $affutage['id'] . '/terminer', $entete);
        self::assertResponseIsSuccessful();
        self::assertSame('termine', $client->getResponse()->toArray()['statut']);
    }

    public function testMaintenanceParcSortEtRentreDuDisponible(): void
    {
        [$client, $entete] = $this->technicienSurA();
        $client->disableReboot();

        $idParc = $this->idParcPatins(43);
        $client->request('GET', '/api/patinoire_parc_patins/' . $idParc, $entete);
        $avant = $client->getResponse()->toArray();
        self::assertSame(5, $avant['quantiteDisponible']);

        $client->request('GET', '/me', $entete);
        $idTechnicien = $client->getResponse()->toArray()['id'];

        $client->request('POST', '/api/patinoire/affutages', $entete + [
            'json' => [
                'type' => 'maintenance_parc',
                'technicien' => '/api/utilisateurs/' . $idTechnicien,
                'parcPatins' => '/api/patinoire_parc_patins/' . $idParc,
            ],
        ]);
        self::assertResponseIsSuccessful();
        $affutage = $client->getResponse()->toArray();
        self::assertSame('maintenance_parc', $affutage['type']);
        self::assertNull($affutage['ligneVente'] ?? null, 'CA-7 : aucune ligne de vente associée (opération interne).');

        $client->request('GET', '/api/patinoire_parc_patins/' . $idParc, $entete);
        $pendant = $client->getResponse()->toArray();
        self::assertSame(1, $pendant['quantiteEnAffutage']);
        self::assertSame(4, $pendant['quantiteDisponible'], 'CA-7 : sort du disponible dès la mise en affûtage.');

        $client->request('POST', '/api/patinoire/affutages/' . $affutage['id'] . '/terminer', $entete);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/patinoire_parc_patins/' . $idParc, $entete);
        $apres = $client->getResponse()->toArray();
        self::assertSame(0, $apres['quantiteEnAffutage']);
        self::assertSame(5, $apres['quantiteDisponible'], 'CA-7 : redevient disponible une fois « bon ».');
    }
}
