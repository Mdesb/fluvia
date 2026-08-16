<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Api;

use App\DataFixtures\SocleFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Reservation\ReservationApiTestCase;

/**
 * Cloisonnement multi-entités (RG-SOCLE-05, socle réutilisé) : un utilisateur ne voit/n'agit que sur
 * l'établissement où il est affecté.
 */
final class CloisonnementTest extends ReservationApiTestCase
{
    public function testUtilisateurNeVoitQueSonEtablissement(): void
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);

        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $idB = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);

        // Sur A : le lecteur possède *.lire → lecture autorisée.
        $client->request('GET', '/api/reservation_ressources', ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]]);
        self::assertResponseIsSuccessful();

        // Sur B : aucune affectation → aucune permission effective → accès refusé.
        $client->request('GET', '/api/reservation_ressources', ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idB]]);
        self::assertResponseStatusCodeSame(403, 'RG-SOCLE-05 : aucun accès hors périmètre affecté.');
    }

    public function testAdminNeVoitPasLesRessourcesDunAutreGroupe(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        // Groupe B (CrmFixtures) : établissement C, aucune affectation pour l'admin du groupe A.
        $idEtabC = $this->idEtablissement(\App\Crm\DataFixtures\CrmFixtures::ETAB_C_NOM);

        $client->request('GET', '/api/reservation_ressources', [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => [ContexteEtablissement::HEADER => $idEtabC],
        ]);
        self::assertResponseStatusCodeSame(403, 'RG-SOCLE-05 : cloisonnement Groupe (l\'admin du groupe A n\'est pas affecté sur le groupe B/établissement C).');
    }
}
