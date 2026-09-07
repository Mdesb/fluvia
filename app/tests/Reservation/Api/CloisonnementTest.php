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
        self::assertResponseStatusCodeSame(404, 'RG-SOCLE-05 : aucun accès hors périmètre affecté.');

        // TÉMOIN DU VOTER (07/09) : l'en-tête EST dans la portée (le listener laisse passer),
        // mais la permission d'écriture manque (LECTEUR n'a que *.lire) → le voter doit refuser.
        // Sans ce cas, depuis e915c94e ce test ne prouve plus que le refus du listener (404).
        $client->request('POST', '/api/reservation_activites', ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA], 'json' => []]);
        self::assertResponseStatusCodeSame(403, 'Le voter refuse une écriture dans la portée sans la permission requise (le listener, lui, a laissé passer l\'en-tête).');
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

    /**
     * D41 — l'établissement d'une création vient de la session serveur, jamais du corps.
     *
     * Le montage est délibérément *légitime* : l'admin est affecté à A **et** à B, donc rien ne lui
     * interdit de travailler sur B. Ce que le test vérifie n'est pas un refus, c'est que le champ
     * envoyé est **ignoré** — la ressource naît dans l'établissement du contexte, pas dans celui que
     * le corps désigne. Un test bâti sur un établissement interdit aurait été vert grâce au garde
     * global de D41, sans rien prouver sur la conception.
     */
    public function testLEtablissementDuneCreationVientDuContexteEtNonDuCorps(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $idB = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);

        $ressource = $client->request('POST', '/api/reservation_ressources', $entete + [
            'json' => [
                'etablissement' => '/api/etablissements/' . $idB,
                'codeType' => 'table',
                'libelle' => 'Table dont l\'etablissement est impose ' . uniqid(),
                'capacitePropre' => 4,
            ],
        ])->toArray();
        self::assertResponseIsSuccessful();

        /** @var \Doctrine\ORM\EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $entite = $em->getRepository(\App\Reservation\Entity\Ressource::class)->find($ressource['id']);
        self::assertNotNull($entite);
        self::assertSame($idA, (string) $entite->getEtablissement()?->getId(), 'Le contexte serveur gagne sur le corps de la requete.');
        self::assertNotSame($idB, (string) $entite->getEtablissement()?->getId());
    }

    /**
     * RG-SOCLE-05 — les disponibilités et indisponibilités ne portent pas d'établissement : elles le
     * tiennent de leur ressource. Sans jointure, leurs collections étaient lisibles d'un
     * établissement à l'autre — on voyait les plages d'ouverture et les fermetures exceptionnelles
     * des voisins, c'est-à-dire leur activité réelle.
     */
    public function testLesDisponibilitesDunEtablissementHorsPerimetreNeSontPasListees(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idRessourceEtrangere = $this->creerRessourceHorsPerimetre();

        /** @var \Doctrine\ORM\EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $ressource = $em->getRepository(\App\Reservation\Entity\Ressource::class)->find($idRessourceEtrangere);
        self::assertNotNull($ressource);
        $dispo = (new \App\Reservation\Entity\DisponibiliteRessource())->setRessource($ressource)
            ->setJourSemaine(1)
            ->setHeureDebut(new \DateTimeImmutable('2026-01-01 08:00:00'))
            ->setHeureFin(new \DateTimeImmutable('2026-01-01 18:00:00'));
        $em->persist($dispo);
        $em->flush();

        $client->request('GET', '/api/reservation_disponibilites', $entete);
        self::assertResponseIsSuccessful();
        $membres = $client->getResponse()->toArray()['member'] ?? $client->getResponse()->toArray()['hydra:member'];
        $ids = array_map(static fn (array $d): string => $d['id'], $membres);
        self::assertNotContains((string) $dispo->getId(), $ids, 'La disponibilité d\'un établissement hors périmètre ne doit pas être listée.');
    }

    /**
     * Et l'écriture : déclarer une disponibilité SUR la ressource d'un autre établissement.
     * `risque_ecriture` de la ligne de base, mot pour mot — la lecture corrigée ne suffit pas si
     * l'écriture reste ouverte.
     */
    public function testOnNeDeclarePasUneDisponibiliteSurLaRessourceDunAutreEtablissement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idRessourceEtrangere = $this->creerRessourceHorsPerimetre();

        $client->request('POST', '/api/reservation_disponibilites', $entete + [
            'json' => [
                'ressource' => '/api/reservation_ressources/' . $idRessourceEtrangere,
                'jourSemaine' => 2,
                'heureDebut' => '09:00:00',
                'heureFin' => '17:00:00',
            ],
        ]);
        self::assertGreaterThanOrEqual(400, $client->getResponse()->getStatusCode(), 'Une ressource hors périmètre ne doit pas être adressable.');

        /** @var \Doctrine\ORM\EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        self::assertSame(
            0,
            (int) $em->getRepository(\App\Reservation\Entity\DisponibiliteRessource::class)
                ->count(['ressource' => $idRessourceEtrangere]),
            'Aucune disponibilité ne doit avoir été écrite sur la ressource étrangère.'
        );
    }

    /** Une ressource sur un établissement neuf, où l'admin n'a aucune affectation. */
    private function creerRessourceHorsPerimetre(): string
    {
        /** @var \Doctrine\ORM\EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etabA = $em->getRepository(\App\Organisation\Entity\Etablissement::class)
            ->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertNotNull($etabA);

        $etranger = (new \App\Organisation\Entity\Etablissement())
            ->setNom('Etablissement hors perimetre ' . uniqid())
            ->setRegion($etabA->getRegion());
        $em->persist($etranger);

        $ressource = (new \App\Reservation\Entity\Ressource())->setEtablissement($etranger)
            ->setCodeType('terrain')->setLibelle('Terrain hors perimetre ' . uniqid())->setCapacitePropre(2);
        $em->persist($ressource);
        $em->flush();

        return (string) $ressource->getId();
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
        self::assertResponseStatusCodeSame(404, 'RG-SOCLE-05 : cloisonnement Groupe (l\'admin du groupe A n\'est pas affecté sur le groupe B/établissement C).');
    }
}
