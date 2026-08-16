<?php

declare(strict_types=1);

namespace App\Tests\Patinoire\Api;

use App\Tests\Patinoire\PatinoireApiTestCase;

/** Validation de la retenue de caution (US-PATIN-04, CA-4, décision actée « patins non rendus/cassés »). */
final class RetenueCautionTest extends PatinoireApiTestCase
{
    public function testValidationMontantDefautGenereTraceComptable(): void
    {
        [$client, $entete] = $this->agentSurA();
        $client->disableReboot();

        $idRetenue = $this->casserUneLocation($client, $entete);

        // Validation au montant par défaut proposé par la grille (15.00) : autorisée à un agent.
        $client->request('POST', '/api/patinoire/retenues/' . $idRetenue . '/valider', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();
        $retenue = $client->getResponse()->toArray();
        self::assertSame('15.00', $retenue['montantRetenu']);
        self::assertFalse($retenue['forcee']);
        self::assertNotNull($retenue['mouvementRegieRef'], 'CA-4 : trace comptable en régie générée.');

        $client->request('GET', '/api/patinoire_caution_location_patins', $entete);
        $caution = $this->membreParLocation($client->getResponse()->toArray(), $this->idDeRelation($retenue['location']));
        self::assertNotNull($caution);
        self::assertSame('retenue_totale', $caution['statut'], 'Montant retenu = montant caution → retenue totale.');
    }

    public function testForcageHorsBaremeReserveAuxAdministrateurs(): void
    {
        [$client, $entete] = $this->agentSurA();
        $client->disableReboot();

        $idRetenue = $this->casserUneLocation($client, $entete);

        // Un agent (sans patinoire.forcer_retenue) ne peut pas s'écarter du barème.
        $client->request('POST', '/api/patinoire/retenues/' . $idRetenue . '/valider', $entete + ['json' => ['montantRetenu' => '25.00']]);
        self::assertResponseStatusCodeSame(403, 'Garde-fou §3 : montant hors barème réservé à patinoire.forcer_retenue.');

        // Un administrateur (patinoire.* couvre forcer_retenue) peut forcer un montant différent.
        [$clientAdmin, $enteteAdmin] = $this->adminSurA();
        $clientAdmin->disableReboot();
        $clientAdmin->request('POST', '/api/patinoire/retenues/' . $idRetenue . '/valider', $enteteAdmin + ['json' => ['montantRetenu' => '25.00']]);
        self::assertResponseIsSuccessful();
        $retenue = $clientAdmin->getResponse()->toArray();
        self::assertSame('25.00', $retenue['montantRetenu']);
        self::assertTrue($retenue['forcee'], 'RG-SOCLE-07 : retenue hors barème journalisée forcee=true.');
    }

    public function testDoubleValidationRefusee(): void
    {
        [$client, $entete] = $this->agentSurA();
        $client->disableReboot();

        $idRetenue = $this->casserUneLocation($client, $entete);
        $client->request('POST', '/api/patinoire/retenues/' . $idRetenue . '/valider', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/patinoire/retenues/' . $idRetenue . '/valider', $entete + ['json' => []]);
        self::assertResponseStatusCodeSame(409, 'Une retenue déjà validée ne peut pas être revalidée.');
    }

    /** @param array<string, mixed> $entete */
    private function casserUneLocation(object $client, array $entete): string
    {
        $idParc = $this->idParcPatins(43);
        $client->request('POST', '/api/patinoire/locations', $entete + [
            'json' => [
                'parcPatins' => '/api/patinoire_parc_patins/' . $idParc,
                'beneficiaire' => '/api/beneficiaires/' . $this->idBeneficiairePayeur(),
            ],
        ]);
        self::assertResponseIsSuccessful();
        $idLocation = $client->getResponse()->toArray()['id'];

        $client->request('POST', '/api/patinoire/locations/' . $idLocation . '/retour', $entete + [
            'json' => ['etatRetour' => 'casse', 'motif' => 'lame cassée'],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/patinoire_retenue_cautions', $entete);
        $retenue = $this->membreParLocation($client->getResponse()->toArray(), $idLocation);
        self::assertNotNull($retenue);

        return $retenue['id'];
    }
}
