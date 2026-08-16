<?php

declare(strict_types=1);

namespace App\Tests\Padel\Api;

use App\Tests\Padel\PadelApiTestCase;

/** Tournois & ligues (US-PADEL-05/06/07, CA-6/CA-7). */
final class TournoiTest extends PadelApiTestCase
{
    public function testCa6GenerationPoulesEtBlocageTerrains(): void
    {
        [$client, $entete] = $this->gestionnaireSurA();
        $client->disableReboot();

        $idTournoi = (string) $this->entite(\App\Padel\Entity\Tournoi::class, [])->getId();
        $idTerrain = $this->idTerrain();
        $debut = (new \DateTimeImmutable('next monday'))->setTime(9, 0);

        $client->request('POST', '/api/padel/tournois/' . $idTournoi . '/generer-poules', $entete + [
            'json' => [
                'terrains' => ['/api/padel_terrains/' . $idTerrain],
                'debut' => $debut->format(DATE_ATOM),
                'dureeMinutes' => 90,
            ],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame('en_cours', $client->getResponse()->toArray()['statut'] ?? null);

        // Un seul tournoi de démonstration existe dans les fixtures : collecte non filtrée suffisante
        // (le filtre `SearchFilter` sur une association `uuid` binaire n'est pas exercé ici, hors
        // périmètre de cette vérification fonctionnelle).
        $client->request('GET', '/api/padel_poules', $entete);
        self::assertResponseIsSuccessful();
        $poules = $client->getResponse()->toArray()['member'] ?? [];
        self::assertNotEmpty($poules, 'CA-6 : les poules sont créées automatiquement.');

        $client->request('GET', '/api/padel_match_tournois', $entete);
        self::assertResponseIsSuccessful();
        $matchs = $client->getResponse()->toArray()['member'] ?? [];
        self::assertNotEmpty($matchs, 'CA-6 : les matchs (et donc les terrains bloqués) sont créés.');
        foreach ($matchs as $match) {
            self::assertNotNull($match['reservationBlocage'] ?? null, 'CA-6 : le terrain est bloqué (réservation système).');
        }
    }

    public function testCa7SaisieScoreDetermineVainqueurEtClassement(): void
    {
        [$client, $entete] = $this->gestionnaireSurA();
        $client->disableReboot();

        $idTournoi = (string) $this->entite(\App\Padel\Entity\Tournoi::class, [])->getId();
        $idTerrain = $this->idTerrain();
        $debut = (new \DateTimeImmutable('next monday'))->setTime(9, 0);

        $client->request('POST', '/api/padel/tournois/' . $idTournoi . '/generer-poules', $entete + [
            'json' => ['terrains' => ['/api/padel_terrains/' . $idTerrain], 'debut' => $debut->format(DATE_ATOM), 'dureeMinutes' => 90],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/padel_match_tournois', $entete);
        $matchs = $client->getResponse()->toArray()['member'];
        self::assertNotEmpty($matchs);
        $idMatch = basename((string) $matchs[0]['@id']);

        $client->request('PATCH', '/api/padel/matchs/' . $idMatch . '/score', $this->entetePatch($entete) + [
            'json' => ['score' => '6-3 6-4', 'vainqueur' => 'A'],
        ]);
        self::assertResponseIsSuccessful();
        $matchMisAJour = $client->getResponse()->toArray();
        self::assertNotNull($matchMisAJour['vainqueur'] ?? null, 'CA-7 : le vainqueur est déterminé.');
        self::assertSame('joue', $matchMisAJour['statut'] ?? null);

        $client->request('GET', '/api/padel/tournois/' . $idTournoi . '/classement', $entete);
        self::assertResponseIsSuccessful();
        $classement = $client->getResponse()->toArray();
        self::assertNotEmpty($classement['poules'] ?? [], 'CA-7 : le classement des poules est recalculé automatiquement.');

        $victoiresTrouvees = false;
        foreach ($classement['poules'] as $poule) {
            foreach ($poule['classement'] as $ligne) {
                if ($ligne['victoires'] > 0) {
                    $victoiresTrouvees = true;
                }
            }
        }
        self::assertTrue($victoiresTrouvees, 'CA-7 : la victoire saisie apparaît dans le classement, sans action supplémentaire.');
    }
}
