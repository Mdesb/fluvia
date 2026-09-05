<?php

declare(strict_types=1);

namespace App\Tests\Stay\Api;

use App\DataFixtures\SocleFixtures;
use App\Stay\DataFixtures\StayFixtures;
use App\Tests\Stay\StayApiTestCase;

/**
 * UN SÉJOUR DOIT DIRE DE QUI IL EST (lot du 05/09).
 *
 * `Stay::$customer` ne portait aucun groupe de sérialisation : on ouvrait un séjour POUR un client —
 * le `POST` l'exige — et plus aucune lecture ne disait lequel. La référence `SEJ-…` était le seul
 * repère, ce qui suffit à appeler quelqu'un au comptoir et pas à savoir qui c'est.
 *
 * ⚠ CE TEST NE SE CONTENTE PAS DE LA PRÉSENCE DU CHAMP. Exposer `customer` en IRI aurait rendu
 * `/api/clients/3f2a91c4-…` : un champ de plus, et une liste toujours illisible. Ce qu'on vérifie
 * est donc le NOM — c'est-à-dire la seule chose qui rend l'écran utilisable.
 *
 * ⚠ ET IL VÉRIFIE AUSSI CE QUI NE DOIT PAS SORTIR. Un groupe de lecture s'élargit bien plus
 * facilement qu'il ne se rétrécit : sans témoin négatif, un `stay:read` ajouté demain sur l'adresse
 * ou la date de naissance passerait inaperçu. Le séjour n'a besoin que de l'identité.
 */
final class StayCustomerVisibilityTest extends StayApiTestCase
{
    public function testLaCollectionNommeLeTitulaireDuSejour(): void
    {
        [$client, $entete] = $this->adminSur($this->idEtablissement(SocleFixtures::ETAB_A_NOM));

        $client->request('GET', '/api/stays', $entete);
        self::assertResponseIsSuccessful();

        $sejours = $client->getResponse()->toArray()['member'] ?? [];
        $cible = null;
        foreach ($sejours as $sejour) {
            if (($sejour['reference'] ?? null) === StayFixtures::REFERENCE_A) {
                $cible = $sejour;
                break;
            }
        }

        self::assertNotNull($cible, 'Le séjour de référence doit être visible depuis son établissement.');
        self::assertArrayHasKey('customer', $cible, 'Sans `customer`, rien ne dit de qui est le séjour.');
        self::assertSame(
            'Martin',
            $cible['customer']['nom'] ?? null,
            "Le nom doit arriver AVEC le séjour : une IRI seule laisserait la liste illisible.",
        );
        self::assertSame('Claire', $cible['customer']['prenom'] ?? null);
    }

    /**
     * Le témoin négatif du groupe de lecture : ce qu'un séjour n'a aucune raison de diffuser.
     *
     * Il ne prouve pas que le cloisonnement fonctionne — `CloisonnementStayTest` s'en charge — mais
     * que la PORTÉE de `stay:read` sur `Client` est restée celle qu'on a voulue.
     */
    public function testLeSejourNeDiffusePasPlusQueLIdentite(): void
    {
        [$client, $entete] = $this->adminSur($this->idEtablissement(SocleFixtures::ETAB_A_NOM));

        $client->request('GET', '/api/stays', $entete);
        self::assertResponseIsSuccessful();

        $sejours = $client->getResponse()->toArray()['member'] ?? [];
        self::assertNotSame([], $sejours, 'Le montage suppose au moins un séjour lisible.');

        $inspectes = 0;
        foreach ($sejours as $sejour) {
            $titulaire = $sejour['customer'] ?? [];
            if (!\is_array($titulaire)) {
                continue;
            }

            // ⚠ SANS CETTE GARDE, LES ASSERTIONS D'ABSENCE PASSENT SUR UN TITULAIRE VIDE — donc
            // précisément le jour où `stay:read` disparaîtrait de `Client`. Un témoin négatif qui
            // se vérifie quand il n'y a rien à voir ne garde rien.
            self::assertArrayHasKey('nom', $titulaire, 'Le titulaire doit être renseigné avant qu’on vérifie ce qu’il ne dit pas.');
            ++$inspectes;

            foreach (['dateNaissance', 'siret', 'email', 'telephone', 'adresse', 'civilite'] as $champ) {
                self::assertArrayNotHasKey(
                    $champ,
                    $titulaire,
                    sprintf('`%s` n’a rien à faire sur la lecture d’un séjour.', $champ),
                );
            }
        }

        self::assertGreaterThan(0, $inspectes, 'Aucun titulaire inspecté : le test n’aurait rien prouvé.');
    }
}
