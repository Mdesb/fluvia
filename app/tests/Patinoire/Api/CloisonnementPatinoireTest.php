<?php

declare(strict_types=1);

namespace App\Tests\Patinoire\Api;

use App\DataFixtures\SocleFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Patinoire\PatinoireApiTestCase;

/**
 * Cloisonnement multi-entités des ressources `patinoire.*` (RG-SOCLE-05) : un utilisateur ne voit/n'agit
 * que sur l'établissement où il est affecté (patron `App\Tests\Padel\Api\CloisonnementTest`).
 */
final class CloisonnementPatinoireTest extends PatinoireApiTestCase
{
    public function testUtilisateurNeVoitQueSonEtablissement(): void
    {
        [$client, $entete, $idA] = $this->adminSurA();
        $client->disableReboot();

        // Le lecteur socle n'a d'affectation que sur l'établissement A.
        $token = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);
        $idB = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);

        foreach (['/api/patinoire_parc_patins', '/api/patinoire_zone_patinoires', '/api/patinoire_saison_ephemeres'] as $chemin) {
            $client->request('GET', $chemin, ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]]);
            self::assertResponseIsSuccessful(sprintf('%s : accessible sur l\'établissement affecté.', $chemin));

            $client->request('GET', $chemin, ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idB]]);
            self::assertResponseStatusCodeSame(403, sprintf('RG-SOCLE-05 : %s inaccessible hors périmètre affecté.', $chemin));
        }
    }

    public function testAdminNeVoitPasLesRessourcesDunAutreGroupe(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idEtabC = $this->idEtablissement(\App\Crm\DataFixtures\CrmFixtures::ETAB_C_NOM);

        $client->request('GET', '/api/patinoire_parc_patins', [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => [ContexteEtablissement::HEADER => $idEtabC],
        ]);
        self::assertResponseStatusCodeSame(403, 'RG-SOCLE-05 : cloisonnement Groupe (admin du groupe A non affecté sur le groupe B/établissement C).');
    }
}
