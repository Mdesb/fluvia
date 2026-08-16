<?php

declare(strict_types=1);

namespace App\Tests\Patinoire\Api;

use App\Tests\Patinoire\PatinoireApiTestCase;

/** Sortie et retour de patins (US-PATIN-02/03, RG-PAT-01/05, CA-2/CA-3/CA-4). */
final class LocationPatinsTest extends PatinoireApiTestCase
{
    public function testSortieBloqueArticleEtEncaisseCaution(): void
    {
        [$client, $entete] = $this->agentSurA();
        $client->disableReboot();

        $idParc = $this->idParcPatins(43); // pointure sans location démo, disponibilité pleine (5).
        $client->request('POST', '/api/patinoire/locations', $entete + [
            'json' => [
                'parcPatins' => '/api/patinoire_parc_patins/' . $idParc,
                'beneficiaire' => '/api/beneficiaires/' . $this->idBeneficiairePayeur(),
                'caution' => '20.00',
            ],
        ]);
        self::assertResponseIsSuccessful();
        $location = $client->getResponse()->toArray();
        self::assertSame('en_cours', $location['statut']);
        $idLocation = $location['id'];

        $client->request('GET', '/api/patinoire_parc_patins/' . $idParc, $entete);
        $parc = $client->getResponse()->toArray();
        self::assertSame(1, $parc['quantiteSortie']);
        self::assertSame(4, $parc['quantiteDisponible'], 'CA-2 : la disponibilité décroît de 1.');

        $client->request('GET', '/api/patinoire_caution_location_patins', $entete);
        self::assertResponseIsSuccessful();
        $caution = $this->membreParLocation($client->getResponse()->toArray(), $idLocation);
        self::assertNotNull($caution, 'CA-2 : une caution est encaissée à la sortie.');
        self::assertSame('20.00', $caution['montant']);
        self::assertSame('encaissee', $caution['statut']);
    }

    public function testSortieRefuseeSiDisponibiliteNulle(): void
    {
        [$client, $entete] = $this->agentSurA();
        $client->disableReboot();

        $idParc = $this->idParcPatins(43);

        // Vide la disponibilité (5 sorties successives).
        for ($i = 0; $i < 5; ++$i) {
            $client->request('POST', '/api/patinoire/locations', $entete + [
                'json' => [
                    'parcPatins' => '/api/patinoire_parc_patins/' . $idParc,
                    'beneficiaire' => '/api/beneficiaires/' . $this->idBeneficiairePayeur(),
                ],
            ]);
            self::assertResponseIsSuccessful();
        }

        $client->request('POST', '/api/patinoire/locations', $entete + [
            'json' => [
                'parcPatins' => '/api/patinoire_parc_patins/' . $idParc,
                'beneficiaire' => '/api/beneficiaires/' . $this->idBeneficiairePayeur(),
            ],
        ]);
        self::assertResponseStatusCodeSame(409, 'CA-2 : sortie refusée si disponibilité nulle, bascule CA-5.');
    }

    public function testRetourBonLibereEtRestitueCaution(): void
    {
        [$client, $entete] = $this->agentSurA();
        $client->disableReboot();

        $idLocation = $this->sortir($client, $entete, 43);

        $client->request('POST', '/api/patinoire/locations/' . $idLocation . '/retour', $entete + [
            'json' => ['etatRetour' => 'bon'],
        ]);
        self::assertResponseIsSuccessful();
        $location = $client->getResponse()->toArray();
        self::assertSame('retournee', $location['statut']);
        self::assertSame('bon', $location['etatRetour']);

        $idParc = $this->idParcPatins(43);
        $client->request('GET', '/api/patinoire_parc_patins/' . $idParc, $entete);
        $parc = $client->getResponse()->toArray();
        self::assertSame(0, $parc['quantiteSortie']);
        self::assertSame(5, $parc['quantiteDisponible'], 'CA-3 : article redevient disponible.');

        $client->request('GET', '/api/patinoire_caution_location_patins', $entete);
        $caution = $this->membreParLocation($client->getResponse()->toArray(), $idLocation);
        self::assertNotNull($caution);
        self::assertSame('liberee', $caution['statut'], 'CA-3 : caution restituée intégralement si état bon.');
    }

    public function testRetourCasseProposeRetenue(): void
    {
        [$client, $entete] = $this->agentSurA();
        $client->disableReboot();

        $idLocation = $this->sortir($client, $entete, 43);

        $client->request('POST', '/api/patinoire/locations/' . $idLocation . '/retour', $entete + [
            'json' => ['etatRetour' => 'casse', 'motif' => 'lame cassée'],
        ]);
        self::assertResponseIsSuccessful();
        $location = $client->getResponse()->toArray();
        self::assertSame('casse', $location['etatRetour']);

        $idParc = $this->idParcPatins(43);
        $client->request('GET', '/api/patinoire_parc_patins/' . $idParc, $entete);
        $parc = $client->getResponse()->toArray();
        self::assertSame(0, $parc['quantiteSortie']);
        self::assertSame(1, $parc['quantiteHS'], 'RG-PAT-06 : article cassé sort définitivement du parc.');
        self::assertSame(4, $parc['quantiteDisponible']);

        $client->request('GET', '/api/patinoire_retenue_cautions', $entete);
        self::assertResponseIsSuccessful();
        $retenue = $this->membreParLocation($client->getResponse()->toArray(), $idLocation);
        self::assertNotNull($retenue, 'CA-3/CA-4 : une retenue proposée est créée.');
        self::assertSame('15.00', $retenue['montantRetenu'], 'CA-4 : montant par défaut de la grille de retenue (casse, forfait 15€).');
    }

    /** @param array<string, mixed> $entete */
    private function sortir(object $client, array $entete, int $pointure): string
    {
        $idParc = $this->idParcPatins($pointure);
        $client->request('POST', '/api/patinoire/locations', $entete + [
            'json' => [
                'parcPatins' => '/api/patinoire_parc_patins/' . $idParc,
                'beneficiaire' => '/api/beneficiaires/' . $this->idBeneficiairePayeur(),
            ],
        ]);
        self::assertResponseIsSuccessful();

        return $client->getResponse()->toArray()['id'];
    }
}
