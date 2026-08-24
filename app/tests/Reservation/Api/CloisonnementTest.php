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

    /**
     * D3/D8 — arbitrer un conflit de récurrence ne doit pas permettre de déplacer un créneau sur la
     * ressource d'un AUTRE établissement.
     *
     * Le cas est vicieux : l'admin de démonstration est affecté à A **et** à B, donc la ressource de
     * B lui est légitimement visible. Ce qui doit être refusé n'est pas la lecture, c'est le
     * rapprochement — le périmètre qui compte est celui du créneau, pas celui de l'utilisateur.
     * Avant ce correctif, la ressource n'était résolue que par son identifiant, et l'arbitrage
     * l'acceptait.
     */
    public function testArbitrageNePeutPasDeplacerUnCreneauSurLaRessourceDunAutreEtablissement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => '/api/reservation_ressources/' . $this->idRessource(\App\Reservation\DataFixtures\ReservationFixtures::RESSOURCE_TERRAIN_LIBELLE),
                'debut' => '2026-09-18T09:00:00+00:00',
                'fin' => '2026-09-18T10:00:00+00:00',
                'capacite' => 4,
            ],
        ]);
        self::assertResponseIsSuccessful();
        $idCreneau = $client->getResponse()->toArray()['id'];
        $ressourceInitiale = $client->getResponse()->toArray()['ressource']['id'] ?? null;
        self::assertNotNull($ressourceInitiale);

        /** @var \Doctrine\ORM\EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etabB = $em->getRepository(\App\Organisation\Entity\Etablissement::class)
            ->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        self::assertNotNull($etabB);
        $ressourceB = (new \App\Reservation\Entity\Ressource())->setEtablissement($etabB)
            ->setCodeType('terrain')->setLibelle('Terrain B ' . uniqid())->setCapacitePropre(4);
        $em->persist($ressourceB);
        $em->flush();

        $client->request('POST', '/api/reservation/creneaux/' . $idCreneau . '/arbitrer', $entete + [
            'json' => ['ressource' => '/api/reservation_ressources/' . $ressourceB->getId()],
        ]);
        // 404 et non 403 : répondre « interdit » confirmerait que cet identifiant existe ailleurs.
        self::assertResponseStatusCodeSame(404);

        // Et surtout : le créneau n'a pas bougé. Un refus qui laisserait l'écriture faite serait pire
        // qu'une absence de refus, parce qu'il aurait l'air d'avoir protégé quelque chose.
        $em->clear();
        $creneau = $em->getRepository(\App\Reservation\Entity\Creneau::class)->find($idCreneau);
        self::assertNotNull($creneau);
        self::assertSame($ressourceInitiale, (string) $creneau->getRessource()?->getId());
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
