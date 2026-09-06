<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\DataFixtures\SocleFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Compta\ComptaApiTestCase;

/**
 * RG-SOCLE-05 (cloisonnement) : un utilisateur sans affectation sur l'établissement porteur du
 * profil exploitant n'a aucun accès (hérité du socle).
 */
final class CloisonnementTest extends ComptaApiTestCase
{
    public function testLectureRefuseeHorsPerimetreEtablissement(): void
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);

        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $idB = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);

        // Le lecteur (affecté à A, permission *.lire) peut lire le plan de comptes de A.
        $client->request('GET', '/api/compte_comptables', ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]]);
        self::assertResponseIsSuccessful();

        // Sur B (aucune affectation) : refusé. Depuis le 06/09 c'est `EstablishmentHeaderListener` qui
        // ferme, AVANT le voter, et en 404 : un 403 confirmerait que B existe (audit, constat 3).
        $client->request('GET', '/api/compte_comptables', ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idB]]);
        self::assertResponseStatusCodeSame(404);
    }
}
