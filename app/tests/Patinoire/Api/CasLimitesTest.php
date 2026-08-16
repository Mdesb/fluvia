<?php

declare(strict_types=1);

namespace App\Tests\Patinoire\Api;

use App\Tests\Patinoire\PatinoireApiTestCase;

/**
 * Cas limite §7 spec-patinoire.md : restitution partielle d'une paire (un seul patin rendu) — ⚠
 * HYPOTHÈSE non tranchée par les sources, retenue comme taux réduit paramétrable de la grille de
 * retenue (motif `restitution_partielle`).
 */
final class CasLimitesTest extends PatinoireApiTestCase
{
    public function testRestitutionPartielleTauxReduit(): void
    {
        [$client, $entete, $idA] = $this->adminSurA();
        $client->disableReboot();

        // Grille dédiée « restitution_partielle » (taux réduit 7.50€, vs 15.00€ pour casse/non-rendu total).
        $client->request('POST', '/api/patinoire_grille_retenues', $entete + [
            'json' => [
                'etablissement' => '/api/etablissements/' . $idA,
                'motif' => 'restitution_partielle',
                'mode' => 'forfait',
                'montantOuTaux' => '7.50',
            ],
        ]);
        self::assertResponseIsSuccessful();

        [$clientAgent, $enteteAgent] = $this->agentSurA();
        $clientAgent->disableReboot();

        $idParc = $this->idParcPatins(43);
        $clientAgent->request('POST', '/api/patinoire/locations', $enteteAgent + [
            'json' => [
                'parcPatins' => '/api/patinoire_parc_patins/' . $idParc,
                'beneficiaire' => '/api/beneficiaires/' . $this->idBeneficiairePayeur(),
            ],
        ]);
        self::assertResponseIsSuccessful();
        $idLocation = $clientAgent->getResponse()->toArray()['id'];

        $clientAgent->request('POST', '/api/patinoire/locations/' . $idLocation . '/retour', $enteteAgent + [
            'json' => ['etatRetour' => 'non_rendu', 'restitutionPartielle' => true, 'motif' => 'un seul patin rendu'],
        ]);
        self::assertResponseIsSuccessful();

        $clientAgent->request('GET', '/api/patinoire_retenue_cautions', $enteteAgent);
        $retenue = $this->membreParLocation($clientAgent->getResponse()->toArray(), $idLocation);
        self::assertNotNull($retenue);
        self::assertSame('7.50', $retenue['montantRetenu'], 'Restitution partielle : taux réduit de la grille dédiée appliqué, distinct du barème non-rendu total.');
    }
}
