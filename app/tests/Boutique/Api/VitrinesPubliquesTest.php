<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Organisation\Entity\Etablissement;
use App\Tests\Boutique\BoutiqueApiTestCase;

/**
 * Comble des manques boutique : `GET /boutique/vitrines-publiques` — point d'entrée public léger
 * (sans permission staff `boutique.lire`), restreint aux vitrines publiées/actives.
 */
final class VitrinesPubliquesTest extends BoutiqueApiTestCase
{
    public function testListeLesVitrinesPubliqueesSansAucuneAuthentification(): void
    {
        $client = static::createClient();
        $reponse = $client->request('GET', '/api/boutique/vitrines-publiques');
        self::assertResponseIsSuccessful();

        $donnees = $reponse->toArray();
        $ids = array_column($donnees['vitrines'], 'id');
        self::assertContains($this->idVitrineA(), $ids);
        self::assertContains($this->idVitrineB(), $ids);

        foreach ($donnees['vitrines'] as $vitrine) {
            self::assertArrayHasKey('nom', $vitrine);
            self::assertArrayHasKey('logo', $vitrine);
            self::assertArrayHasKey('couleurs', $vitrine);
            self::assertArrayHasKey('langues', $vitrine);
            self::assertArrayNotHasKey('etablissement', $vitrine, 'Aucune donnée interne ne doit fuiter.');
            self::assertArrayNotHasKey('canauxActifs', $vitrine);
        }
    }

    public function testUneVitrineDUnEtablissementInactifNApparaitPasDansLeListingPublic(): void
    {
        $etabB = $this->entite(Etablissement::class, ['nom' => \App\DataFixtures\SocleFixtures::ETAB_B_NOM]);
        $etabB->setActif(false);
        $this->em()->flush();
        $this->em()->clear();

        $client = static::createClient();
        $donnees = $client->request('GET', '/api/boutique/vitrines-publiques')->toArray();

        $ids = array_column($donnees['vitrines'], 'id');
        self::assertContains($this->idVitrineA(), $ids);
        self::assertNotContains($this->idVitrineB(), $ids, 'RG-M3-01 : établissement inactif -> vitrine non publiée.');
    }
}
