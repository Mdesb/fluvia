<?php

declare(strict_types=1);

namespace App\Tests\Facturation\Api;

use App\DataFixtures\SocleFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Facturation\FacturationApiTestCase;

/**
 * RG-SOCLE-05 (`plan-facturation.md` §3) : un utilisateur sans affectation sur l'établissement de la
 * facture n'a aucun accès — `PerimetreFacturationExtension`.
 */
final class CloisonnementFacturationTest extends FacturationApiTestCase
{
    public function testUtilisateurHorsEtablissementNAccedePas(): void
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);

        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $idB = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);

        // Le lecteur (affecté à A, permission *.lire) peut lire les factures de A.
        $client->request('GET', '/api/factures', ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]]);
        self::assertResponseIsSuccessful();

        // Sur B (aucune affectation), aucune permission effective → accès refusé.
        $client->request('GET', '/api/factures', ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idB]]);
        self::assertResponseStatusCodeSame(404);
    }
}
