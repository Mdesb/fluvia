<?php

declare(strict_types=1);

namespace App\Tests\Group\Api;

use App\DataFixtures\SocleFixtures;
use App\Group\DataFixtures\GroupFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Group\GroupApiTestCase;

/**
 * Cloisonnement du module `App\Group` (RG-SOCLE-05) : un gestionnaire ne voit et n'agit que sur son
 * établissement actif. Le groupe de démonstration posé sur l'établissement B est le témoin — il ne
 * doit jamais apparaître ni se résoudre pour un gestionnaire de A.
 */
final class CloisonnementTest extends GroupApiTestCase
{
    public function testCollectionNeMontreQueLEtablissementActif(): void
    {
        [$client, $entete] = $this->gestionnaireSurA();

        $client->request('GET', '/api/participant_groups', $entete);
        self::assertResponseIsSuccessful();
        $labels = array_column(self::membres($client->getResponse()->toArray()), 'label');

        self::assertContains(GroupFixtures::GROUPE_A_LABEL, $labels, 'Le groupe de A doit être visible.');
        self::assertNotContains(GroupFixtures::GROUPE_B_LABEL, $labels, 'Le groupe de B ne doit jamais fuiter.');
    }

    public function testItemDUnAutreEtablissementEstIntrouvable(): void
    {
        [$client, $entete] = $this->gestionnaireSurA();
        $idGroupeB = $this->idGroupe(GroupFixtures::GROUPE_B_LABEL);

        $client->request('GET', '/api/participant_groups/' . $idGroupeB, $entete);
        self::assertResponseStatusCodeSame(404, 'Un groupe hors périmètre est introuvable, pas visible.');
    }

    public function testEnTeteHorsPerimetreRefuse(): void
    {
        // Le gestionnaire n'est affecté qu'à A : un en-tête X-Etablissement hors de son périmètre est
        // fermé en 404 par EstablishmentHeaderListener, AVANT même le voter (l'établissement, pour lui,
        // n'existe pas).
        $client = static::createClient();
        $token = $this->jeton($client, GroupFixtures::GESTIONNAIRE_EMAIL, GroupFixtures::GESTIONNAIRE_MDP);
        $idB = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);

        $client->request('GET', '/api/participant_groups', [
            'auth_bearer' => $token,
            'headers' => [ContexteEtablissement::HEADER => $idB],
        ]);
        self::assertResponseStatusCodeSame(404, 'Un établissement hors périmètre est fermé en 404 (RG-SOCLE-05).');
    }
}
