<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Boutique\Entity\Vitrine;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Tests\Boutique\BoutiqueApiTestCase;

/**
 * Revue de sécurité — faille majeure (#2) : une vitrine dont l'établissement est inactif, ou dont le
 * canal `en_ligne` a été coupé, ne doit jamais être exposée publiquement — ni sa fiche, ni son
 * catalogue, ni l'ouverture d'un panier (même règle que `VitrinesPubliquesProvider`, RG-M3-01).
 */
final class VitrineAccessibiliteTest extends BoutiqueApiTestCase
{
    public function testCatalogueDUneVitrineDEtablissementInactifRepond404(): void
    {
        $idVitrineB = $this->idVitrineB();
        $this->rendreEtablissementBInactif();

        $client = static::createClient();
        $client->request('GET', '/api/boutique/vitrines/' . $idVitrineB . '/catalogue');
        self::assertResponseStatusCodeSame(404, 'RG-M3-01 : catalogue d\'une vitrine d\'établissement inactif introuvable.');
    }

    public function testOuvertureDePanierSurUneVitrineDEtablissementInactifRepond404(): void
    {
        $idVitrineB = $this->idVitrineB();
        $this->rendreEtablissementBInactif();

        $client = static::createClient();
        $client->request('POST', '/api/boutique/paniers', ['json' => ['vitrine' => $idVitrineB]]);
        self::assertResponseStatusCodeSame(404, 'RG-M3-01 : ouverture de panier impossible sur une vitrine d\'établissement inactif.');
    }

    public function testFicheVitrinePubliqueDUnEtablissementInactifRepond404(): void
    {
        $idVitrineB = $this->idVitrineB();
        $this->rendreEtablissementBInactif();

        $client = static::createClient();
        $client->request('GET', '/api/boutique/vitrines/' . $idVitrineB);
        self::assertResponseStatusCodeSame(404, 'RG-M3-01 : fiche vitrine publique d\'un établissement inactif introuvable.');
    }

    public function testCatalogueDUneVitrineAuCanalEnLigneCoupeRepond404(): void
    {
        $vitrineB = $this->entite(Vitrine::class, ['etablissement' => $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_B_NOM])]);
        $vitrineB->setCanauxActifs(['app']);
        $this->em()->flush();
        $this->em()->clear();

        $client = static::createClient();
        $client->request('GET', '/api/boutique/vitrines/' . (string) $vitrineB->getId() . '/catalogue');
        self::assertResponseStatusCodeSame(404, 'RG-M3-01 : canal `en_ligne` coupé -> catalogue introuvable.');
    }

    private function rendreEtablissementBInactif(): void
    {
        $etabB = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_B_NOM]);
        $etabB->setActif(false);
        $this->em()->flush();
        $this->em()->clear();
    }
}
