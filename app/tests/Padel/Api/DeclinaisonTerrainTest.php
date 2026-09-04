<?php

declare(strict_types=1);

namespace App\Tests\Padel\Api;

use App\Tests\Padel\PadelApiTestCase;

/**
 * UN TERRAIN QUI SE DÉCLINE — R13 et R14.
 *
 * Maxime a tranché : padel, tennis, squash et badminton relèvent d'une seule verticale. Un terrain,
 * un créneau, une grille tarifaire — la mécanique est identique ; le sport et la surface sont des
 * attributs, pas des modules jumeaux.
 *
 * ⚠ CE TEST EXISTE PARCE QUE `terrain:write` NE DÉCIDE RIEN À LA CRÉATION.
 * `CreerTerrainProcessor` ne désérialise pas : il reconstruit un `TerrainPadel` neuf à la main
 * depuis le corps. Poser deux colonnes dans le groupe d'écriture et s'arrêter là aurait donné une
 * création qui répond 201 et n'enregistre RIEN — pendant que le `Patch`, lui, standard, les
 * écrirait très bien. Un défaut qui ne se voit qu'à la création est le pire des deux.
 *
 * C'est la même forme que `Produit::$categories` (267a735b) et que les trois « écritures acceptées
 * qui n'enregistrent rien » du 31/08.
 */
final class DeclinaisonTerrainTest extends PadelApiTestCase
{
    public function testUnCourtDeTennisEnTerreBattueSEcritEtSeRelit(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/padel/terrains', $entete + [
            'json' => [
                'libelle' => 'Court 1 · tennis',
                'type' => 'outdoor',
                'sport' => 'tennis',
                'surface' => 'clay',
                'dureesAutoriseesMinutes' => [60, 90],
            ],
        ]);
        self::assertResponseIsSuccessful();
        $cree = $client->getResponse()->toArray();

        self::assertSame('tennis', $cree['sport'] ?? null, 'le sport doit revenir tel qu’il a été saisi');
        self::assertSame('clay', $cree['surface'] ?? null, 'la surface aussi — un 201 ne prouve aucune écriture');

        // ⚠ LE TÉMOIN QUI COMPTE : la RELECTURE. La réponse d'un POST peut refléter l'objet en
        // mémoire sans que rien n'ait touché la base.
        $client->request('GET', (string) $cree['@id'], $entete);
        self::assertResponseIsSuccessful();
        $relu = $client->getResponse()->toArray();

        self::assertSame('tennis', $relu['sport'] ?? null, 'et survivre à la relecture');
        self::assertSame('clay', $relu['surface'] ?? null, 'et survivre à la relecture');
    }

    /**
     * ⚠ UNE SURFACE NON DITE RESTE INCONNUE — ELLE NE PREND PAS DE VALEUR PAR DÉFAUT.
     *
     * Le sport, lui, retombe sur `padel` : c'est le constat d'un module padel dans un club de padel.
     * La surface n'a aucun défaut légitime — écrire « résine » inventerait un fait qu'aucun relevé
     * n'a constaté (D66-ter). `null` dit « on ne sait pas », et c'est la vérité.
     */
    public function testUneSurfaceNonDiteResteInconnue(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/padel/terrains', $entete + [
            'json' => [
                'libelle' => 'Terrain 2 · sans relevé',
                'type' => 'indoor',
                'dureesAutoriseesMinutes' => [90],
            ],
        ]);
        self::assertResponseIsSuccessful();
        $cree = $client->getResponse()->toArray();

        self::assertSame('padel', $cree['sport'] ?? null, 'sans sport dit, un terrain de ce module est du padel');

        // ⚠ API PLATFORM OMET LES VALEURS NULLES : la clé DISPARAÎT au lieu de valoir `null`.
        // Absent et nul disent donc ici la même chose, et `?? null` est la bonne lecture.
        self::assertNull(
            $cree['surface'] ?? null,
            'une surface qu’on n’a pas relevée ne doit pas être inventée par un défaut',
        );
    }

    /**
     * ⚠ UN SPORT INCONNU EST REFUSÉ, ET C'EST LE SÉRIALISEUR QUI LE REFUSE — PAS LE PROCESSEUR.
     *
     * Mesuré en le cassant : `DeserializeProvider` tourne AVANT `CreerTerrainProcessor`. Comme
     * `sport` est dans `terrain:write` avec un `enumType`, une valeur hors énumération part en 400
     * (« The data must belong to a backed enumeration ») et le processeur n'est jamais atteint.
     *
     * Mon premier jet attendait l'inverse — un repli silencieux sur le padel — et le commentaire du
     * processeur l'annonçait. C'était faux dans les deux endroits, et le refus vaut mieux : un
     * court de tennis silencieusement transformé en terrain de padel serait un mensonge en base.
     *
     * Ce test garde donc la frontière : si quelqu'un sort `sport` du groupe d'écriture, le refus
     * disparaît sans bruit et cette création recommencerait à passer.
     */
    public function testUnSportInconnuEstRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/padel/terrains', $entete + [
            'json' => [
                'libelle' => 'Terrain 3 · sport exotique',
                'type' => 'indoor',
                'sport' => 'pickleball',
                'dureesAutoriseesMinutes' => [60],
            ],
        ]);
        self::assertResponseStatusCodeSame(
            400,
            'un sport hors énumération doit être refusé, et non retomber en silence sur le padel',
        );
    }
}
