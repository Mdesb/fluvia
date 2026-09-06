<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\DataFixtures\SocleFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Vente\VenteApiTestCase;

/**
 * Cloisonnement multi-entités (RG-SOCLE-05, socle réutilisé) : un utilisateur ne peut agir que sur
 * l'établissement où il est affecté. Le lecteur (affecté à A) est refusé sur B.
 */
final class CloisonnementTest extends VenteApiTestCase
{
    public function testAccesRefuseHorsPerimetre(): void
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);

        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $idB = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);

        // Sur A : le lecteur possède *.lire → lecture autorisée.
        $client->request('GET', '/api/ventes', ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]]);
        self::assertResponseIsSuccessful();

        // Sur B : aucune affectation → refusé. Depuis le 06/09 c'est `EstablishmentHeaderListener` qui
        // ferme, AVANT le voter, et en 404 : un 403 confirmerait que B existe (audit, constat 3).
        $client->request('GET', '/api/ventes', ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idB]]);
        self::assertResponseStatusCodeSame(404);
    }
}
