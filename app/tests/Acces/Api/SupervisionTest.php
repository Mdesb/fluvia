<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Securite\Service\ContexteEtablissement;
use App\Tests\Acces\AccesApiTestCase;

/**
 * Supervision temps réel & ouverture manuelle (US-L3-06, écran A-03, CA-7) : jauge/flux/incidents
 * s'actualisent ; une ouverture manuelle est tracée (agent, motif, horodatage).
 */
final class SupervisionTest extends AccesApiTestCase
{
    /**
     * ⚠ RÉGRESSION DE CLOISONNEMENT — il suffisait d'OMETTRE UN EN-TÊTE pour voir tous les sites.
     *
     * `etablissementActif()` rend `null` quand `X-Etablissement` manque : il ne lève pas. Les
     * critères devenaient `[]`, et `findBy([])` veut dire « tout ». Mesuré le 30/08 sur la préprod,
     * même jeton, seule l'en-tête retirée : 2 contrôleurs et 4 jauges devenaient 6 et 8, avec les
     * libellés et les états d'un AUTRE établissement.
     *
     * Ce test porte un témoin positif — la requête avec en-tête doit rendre des contrôleurs. Sans
     * lui, un `assertResponseStatusCodeSame(422)` resterait vert le jour où la route disparaît, et
     * le test dirait « cloisonné » d'une ressource qui n'existe plus.
     */
    public function testSupervisionSansEtablissementActifRefuseAuLieuDeToutRendre(): void
    {
        [$client, $entete] = $this->adminSurA();

        // Témoin positif : avec l'en-tête, la vue répond et elle est peuplée.
        $client->request('GET', '/api/acces/supervision', $entete);
        self::assertResponseIsSuccessful();
        self::assertNotEmpty($client->getResponse()->toArray()['controleurs']);

        // Même jeton, même route, sans l'en-tête : on REFUSE, on ne rend pas tout.
        $sans = $entete;
        unset($sans['headers'][ContexteEtablissement::HEADER]);
        $client->request('GET', '/api/acces/supervision', $sans);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayNotHasKey('controleurs', $client->getResponse()->toArray(false));
    }

    public function testCa7SupervisionExposeJaugesControleursEtIncidents(): void
    {
        [$client, $entete] = $this->adminSurA();

        // Un refus (support inconnu) doit apparaître en incident.
        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => ['equipement' => '/api/equipements/' . $this->idEquipement(), 'identifiantSupport' => 'INCONNU-0001'],
        ]);
        self::assertSame('refuse', $client->getResponse()->toArray()['resultat']);

        $client->request('GET', '/api/acces/supervision', $entete);
        self::assertResponseIsSuccessful();
        $vue = $client->getResponse()->toArray();

        self::assertNotEmpty($vue['jauges']);
        self::assertNotEmpty($vue['controleurs']);
        self::assertSame('en_ligne', $vue['controleurs'][0]['etat']);
        self::assertNotEmpty($vue['incidents']);
    }

    public function testCa7OuvertureManuelleTraceeAgentMotifHorodatage(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/acces/passages/manuel', $entete + [
            'json' => ['equipement' => '/api/equipements/' . $this->idEquipement(), 'motif' => 'Badge défectueux, franchissement forcé'],
        ]);
        self::assertResponseIsSuccessful();
        $passage = $client->getResponse()->toArray();

        self::assertSame('valide', $passage['resultat']);
        self::assertSame('ouverture_manuelle', $passage['codeMotif']);
        self::assertSame('Badge défectueux, franchissement forcé', $passage['motif']);
        self::assertNotEmpty($passage['agent']);
        self::assertNotEmpty($passage['horodatage']);
    }

    public function testCa7OuvertureManuelleSansMotifRefusee(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/acces/passages/manuel', $entete + [
            'json' => ['equipement' => '/api/equipements/' . $this->idEquipement(), 'motif' => ''],
        ]);
        self::assertResponseStatusCodeSame(422);
    }
}
