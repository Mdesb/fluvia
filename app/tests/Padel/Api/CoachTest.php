<?php

declare(strict_types=1);

namespace App\Tests\Padel\Api;

use App\Reservation\Entity\Ressource;
use App\Tests\Padel\PadelApiTestCase;

/** Réservation avec coach (US-PADEL-09, CA-10). */
final class CoachTest extends PadelApiTestCase
{
    public function testCa10CoachDejaEngageRefuseSinonTarifMajore(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idTerrain1 = $this->idTerrain();
        $idCoach = (string) $this->entite(Ressource::class, ['codeType' => 'coach_padel'])->getId();
        $debut = (new \DateTimeImmutable('next monday'))->setTime(19, 0);

        $client->request('POST', '/api/padel/terrains/' . $idTerrain1 . '/reservations', $entete + [
            'json' => [
                'debut' => $debut->format(DATE_ATOM),
                'dureeMinutes' => 90,
                'organisateur' => '/api/beneficiaires/' . $this->idJoueur(1),
                'avecCoach' => true,
                'coachRessource' => '/api/reservation_ressources/' . $idCoach,
            ],
        ]);
        self::assertResponseIsSuccessful();
        $donnees = $client->getResponse()->toArray();
        self::assertTrue($donnees['avecCoach'] ?? false);

        $idReservation = basename((string) $donnees['reservation']);
        $client->request('GET', '/api/reservations/' . $idReservation, $entete);
        self::assertResponseIsSuccessful();
        // Tarif majoré : 38.00€ (pleine/non-membre/90min) + 15.00€ de majoration coach (fixture) = 53.00€.
        self::assertSame('53.00', $client->getResponse()->toArray()['montantDu'] ?? null, 'CA-10 : tarif majoré avec coach.');

        // Un second terrain accueille une autre réservation « avec coach » sur une fenêtre chevauchante
        // (même coach, terrain différent) : refusée par non-chevauchement de la ressource coach.
        $client->request('POST', '/api/padel/terrains', $entete + [
            'json' => ['libelle' => 'Terrain padel n°2 (outdoor)', 'type' => 'outdoor', 'dureesAutoriseesMinutes' => [60, 90]],
        ]);
        self::assertResponseIsSuccessful();
        $idTerrain2 = $client->getResponse()->toArray()['id'];

        // Sans grille tarifaire sur ce 2e terrain, la tentative échoue *avant* le contrôle coach si le
        // tarif n'est pas paramétré : on choisit donc une fenêtre pleine/90min identique au terrain 1,
        // et on associe manuellement la même grille tarifaire pour rendre le calcul possible.
        $this->dupliquerGrilleTarifaire($idTerrain1, $idTerrain2);

        $client->request('POST', '/api/padel/terrains/' . $idTerrain2 . '/reservations', $entete + [
            'json' => [
                'debut' => $debut->modify('+30 minutes')->format(DATE_ATOM),
                'dureeMinutes' => 90,
                'organisateur' => '/api/beneficiaires/' . $this->idJoueur(2),
                'avecCoach' => true,
                'coachRessource' => '/api/reservation_ressources/' . $idCoach,
            ],
        ]);
        self::assertResponseStatusCodeSame(409, 'CA-10 : coach déjà engagé sur une fenêtre chevauchante, refusé.');
    }

    private function dupliquerGrilleTarifaire(string $idTerrain1, string $idTerrain2): void
    {
        /** @var \Doctrine\ORM\EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $terrain1 = $em->getRepository(\App\Padel\Entity\TerrainPadel::class)->find($idTerrain1);
        $terrain2 = $em->getRepository(\App\Padel\Entity\TerrainPadel::class)->find($idTerrain2);
        $grilles = $em->getRepository(\App\Padel\Entity\GrilleTarifaireTerrain::class)->findBy(['terrain' => $terrain1]);
        foreach ($grilles as $grille) {
            $copie = (new \App\Padel\Entity\GrilleTarifaireTerrain())
                ->setTerrain($terrain2)
                ->setPlageHoraire($grille->getPlageHoraire())
                ->setStatutJoueur($grille->getStatutJoueur())
                ->setDureeMinutes($grille->getDureeMinutes())
                ->setPrix($grille->getPrix());
            $em->persist($copie);
        }
        $em->flush();
    }
}
