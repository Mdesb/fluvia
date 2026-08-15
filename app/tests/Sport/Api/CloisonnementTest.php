<?php

declare(strict_types=1);

namespace App\Tests\Sport\Api;

use App\Crm\DataFixtures\CrmFixtures;
use App\DataFixtures\SocleFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Sport\SportApiTestCase;

/**
 * Cloisonnement multi-entités (RG-SOCLE-05, réutilisé) : un utilisateur affecté à un autre
 * établissement (même avec les permissions `sport.*` requises) ne voit pas l'abonnement d'un
 * établissement distinct.
 */
final class CloisonnementTest extends SportApiTestCase
{
    public function testAgentDunAutreEtablissementNeVoitPasLabonnementDeLetablissementA(): void
    {
        $client = static::createClient();
        $token = $this->jeton($client, CrmFixtures::AGENT_B_EMAIL, CrmFixtures::AGENT_B_MDP);
        $idEtabC = $this->idEtablissement(CrmFixtures::ETAB_C_NOM);
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idEtabC]];

        $client->request('GET', '/api/abonnement_fitnesses', $entete);
        self::assertResponseIsSuccessful();
        $liste = $client->getResponse()->toArray();
        $membres = $liste['member'] ?? $liste['hydra:member'];
        self::assertEmpty($membres, 'Aucun abonnement visible hors périmètre établissement (RG-SOCLE-05).');

        $client->request('GET', '/api/abonnement_fitnesses/' . $this->idAbonnementDemo(), $entete);
        self::assertResponseStatusCodeSame(404, 'Un agent hors périmètre ne doit pas voir un abonnement d\'un autre établissement.');
    }

    public function testAgentSansPermissionSportNePeutPasSouscrire(): void
    {
        [$client] = $this->adminSurA();
        $token = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);
        $lecteurEntete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_A_NOM)]];

        // Le lecteur n'a que `*.lire` (RG-SOCLE-03) : `sport.gerer_abonnement` n'est pas couvert.
        $client->request('POST', '/api/sport/abonnements/souscrire', $lecteurEntete + ['json' => []]);
        self::assertResponseStatusCodeSame(403);
    }
}
