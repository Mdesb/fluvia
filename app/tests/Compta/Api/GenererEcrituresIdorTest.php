<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\DataFixtures\SocleFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Compta\ComptaApiTestCase;

/**
 * Correctif sécurité obligatoire (FIN-1, §0.5 du plan) : `GenererEcrituresProcessor` résolvait
 * `profilExploitant` depuis le corps de requête **sans jamais vérifier** qu'il appartient à
 * l'établissement actif — IDOR cross-tenant réel. Reproduit l'IDOR puis prouve le refus (404) après
 * correctif, sans régression sur le cas légitime (même établissement, `GenerationEcritureTest`).
 */
final class GenererEcrituresIdorTest extends ComptaApiTestCase
{
    public function testProfilDunAutreEtablissementRefuse404(): void
    {
        [$client, $entete] = $this->adminSurA();

        // Le profil exploitant n'existe que sur l'établissement A (ComptaFixtures). Même utilisateur
        // (admin, affecté sur A ET B, RG-SOCLE-05) mais établissement B actif : le profil référencé
        // dans le corps n'est PAS couvert par l'établissement actif -> IDOR reproduit puis refusé.
        $idB = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);
        $enteteB = ['auth_bearer' => $entete['auth_bearer'], 'headers' => [ContexteEtablissement::HEADER => $idB]];

        $client->request('POST', '/api/compta/ecritures/generer', $enteteB + [
            'json' => ['profilExploitant' => '/api/profil_exploitants/' . $this->idProfilExploitant()],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testProfilDuMemeEtablissementResteAutorise(): void
    {
        // Non-régression : le cas légitime (établissement porteur du profil) continue de fonctionner.
        [$client, $entete] = $this->adminSurA();

        $reponse = $client->request('POST', '/api/compta/ecritures/generer', $entete + [
            'json' => ['profilExploitant' => '/api/profil_exploitants/' . $this->idProfilExploitant()],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('ecrituresGenerees', $reponse);
    }
}
