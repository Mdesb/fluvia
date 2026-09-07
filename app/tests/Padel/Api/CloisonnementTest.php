<?php

declare(strict_types=1);

namespace App\Tests\Padel\Api;

use App\DataFixtures\SocleFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Padel\PadelApiTestCase;

/**
 * Cloisonnement multi-entités des ressources `padel.*` (RG-SOCLE-05) : un utilisateur ne voit/n'agit
 * que sur l'établissement où il est affecté.
 */
final class CloisonnementTest extends PadelApiTestCase
{
    public function testAgentSansAffectationSurEtablissementBRefuse(): void
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);

        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $idB = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);

        foreach (['/api/padel_terrains', '/api/padel_tournois', '/api/padel_niveau_joueurs', '/api/padel_reservations'] as $chemin) {
            $client->request('GET', $chemin, ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]]);
            self::assertResponseIsSuccessful(sprintf('%s : accessible sur l\'établissement affecté.', $chemin));

            $client->request('GET', $chemin, ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idB]]);
            self::assertResponseStatusCodeSame(404, sprintf('RG-SOCLE-05 : %s inaccessible hors périmètre affecté.', $chemin));
        }
    }

    public function testAdminNeVoitPasLesRessourcesDunAutreGroupe(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idEtabC = $this->idEtablissement(\App\Crm\DataFixtures\CrmFixtures::ETAB_C_NOM);

        $client->request('GET', '/api/padel_terrains', [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => [ContexteEtablissement::HEADER => $idEtabC],
        ]);
        self::assertResponseStatusCodeSame(404, 'RG-SOCLE-05 : cloisonnement Groupe (admin du groupe A non affecté sur le groupe B/établissement C).');
    }
}
