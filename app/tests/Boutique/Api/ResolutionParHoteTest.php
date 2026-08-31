<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Boutique\Entity\Vitrine;
use App\Boutique\Service\VitrineResolver;
use App\Organisation\Entity\Etablissement;
use App\Tests\Boutique\BoutiqueApiTestCase;

/**
 * D104 — la boutique se résout depuis l'HÔTE : `piscine-a.fluvia-app.com`.
 *
 * ⚠ **L'en-tête `Host` est fourni par l'appelant, et aucun `trusted_hosts` n'est déclaré dans ce
 * dépôt.** La résolution doit donc échouer fermée : tout ce qui n'est pas exactement un
 * sous-domaine à une étiquette de notre domaine rend `null`, jamais une vitrine par défaut.
 *
 * Ce fichier teste d'abord ce qui doit MARCHER, puis les quatre chemins qui doivent être refusés —
 * et chacun de ces quatre correspond à une erreur qu'on écrit naturellement.
 */
final class ResolutionParHoteTest extends BoutiqueApiTestCase
{
    private function resolveur(string $domaine = 'fluvia-app.com'): VitrineResolver
    {
        return new VitrineResolver($this->em(), $domaine);
    }

    public function testUnSousDomaineDesigneLaVitrineDuMemeNom(): void
    {
        $vitrine = $this->resolveur()->resoudreParHote('piscine-a.fluvia-app.com');

        self::assertInstanceOf(Vitrine::class, $vitrine);
        self::assertSame('piscine-a', $vitrine->getSlug());
    }

    public function testLePortEtLaCasseNeChangentRien(): void
    {
        foreach (['piscine-a.fluvia-app.com:8443', 'Piscine-A.Fluvia-App.com', ' piscine-a.fluvia-app.com '] as $hote) {
            $vitrine = $this->resolveur()->resoudreParHote($hote);
            self::assertInstanceOf(Vitrine::class, $vitrine, sprintf('« %s » désigne la même boutique.', $hote));
            self::assertSame('piscine-a', $vitrine->getSlug());
        }
    }

    /**
     * ⚠ LE PIÈGE DU SUFFIXE SANS POINT.
     *
     * `str_ends_with($hote, 'fluvia-app.com')` — la forme qu'on écrit spontanément — est vraie pour
     * `mechantfluvia-app.com`, un domaine que n'importe qui peut acheter. Le suffixe doit porter son
     * point.
     */
    public function testUnDomaineQuiRessembleAuNotreSansEnEtreUnNeResoutPas(): void
    {
        foreach ([
            'piscine-a.mechantfluvia-app.com',
            'mechantfluvia-app.com',
            'piscine-a.fluvia-app.com.attaquant.fr',
            'fluvia-app.com',
        ] as $hote) {
            self::assertNull(
                $this->resolveur()->resoudreParHote($hote),
                sprintf('« %s » n’est pas un sous-domaine de fluvia-app.com.', $hote)
            );
        }
    }

    /**
     * `a.b.fluvia-app.com` ne doit pas aller chercher la vitrine nommée « a.b ».
     */
    public function testUneEtiquetteAPlusieursNiveauxNeResoutPas(): void
    {
        self::assertNull($this->resolveur()->resoudreParHote('x.piscine-a.fluvia-app.com'));
    }

    /**
     * D106 : `pro.` sert le back-office. Il ne doit jamais servir AUSSI une boutique — même si une
     * vitrine créée avant T4 en portait le nom.
     */
    public function testUneEtiquetteReserveeNeResoutJamais(): void
    {
        $etabA = $this->entite(Etablissement::class, ['nom' => \App\DataFixtures\SocleFixtures::ETAB_A_NOM]);
        $vitrine = $this->entite(Vitrine::class, ['etablissement' => $etabA]);

        // On force en base un nom que la validation refuse désormais : c'est exactement la vitrine
        // héritée que le contrôle doit continuer de protéger.
        $this->em()->getConnection()->executeStatement(
            'UPDATE bou_vitrine SET slug = :s WHERE id = :i',
            ['s' => 'pro', 'i' => $vitrine->getId()->toBinary()]
        );
        $this->em()->clear();

        self::assertNull(
            $this->resolveur()->resoudreParHote('pro.fluvia-app.com'),
            'Une vitrine héritée nommée « pro » ne doit pas capter l’hôte du back-office.'
        );
    }

    /**
     * ⚠ CE TEST EXISTE PARCE QUE LE SABOTAGE CORRESPONDANT N'ETAIT PAS ATTRAPE.
     *
     * Retirer `str_contains($etiquette, '.')` ne cassait aucun test : `x.piscine-a.fluvia-app.com`
     * donne l'étiquette `x.piscine-a`, qu'aucune vitrine ne porte — donc `null` de toute façon. Le
     * contrôle était juste **par coïncidence**, la coïncidence étant que la validation de format
     * interdit le point. Deux protections, une seule mesurée : si celle du format tombe, celle-ci
     * tombe en silence.
     *
     * On force donc en base ce que la validation refuse mais qu'une base héritée peut contenir.
     */
    public function testUnSlugHeriteContenantUnPointNEstPasServiParUnHoteAPlusieursNiveaux(): void
    {
        $etabA = $this->entite(Etablissement::class, ['nom' => \App\DataFixtures\SocleFixtures::ETAB_A_NOM]);
        $vitrine = $this->entite(Vitrine::class, ['etablissement' => $etabA]);

        $this->em()->getConnection()->executeStatement(
            'UPDATE bou_vitrine SET slug = :s WHERE id = :i',
            ['s' => 'x.piscine-a', 'i' => $vitrine->getId()->toBinary()]
        );
        $this->em()->clear();

        // Témoin : la vitrine EST bien nommée ainsi en base — sinon ce test passerait pour rien.
        self::assertSame('x.piscine-a', $this->em()->getConnection()->fetchOne(
            'SELECT slug FROM bou_vitrine WHERE id = :i',
            ['i' => $vitrine->getId()->toBinary()]
        ));

        self::assertNull(
            $this->resolveur()->resoudreParHote('x.piscine-a.fluvia-app.com'),
            'Une étiquette à plusieurs niveaux ne désigne pas la vitrine « x.piscine-a ».'
        );
    }

    /**
     * ⚠ CE TEST AUSSI EXISTE PARCE QU'UN SABOTAGE N'ETAIT PAS ATTRAPE.
     *
     * `estVisibleDuPublic()` a DEUX conditions — établissement actif, canal `en_ligne`. Je ne
     * testais que la première. Un exploitant qui coupe la vente en ligne aurait vu sa boutique
     * servie quand même, par son propre hôte.
     */
    public function testUneBoutiqueDontLaVenteEnLigneEstCoupeeNEstPasServieParSonHote(): void
    {
        $etabA = $this->entite(Etablissement::class, ['nom' => \App\DataFixtures\SocleFixtures::ETAB_A_NOM]);
        $vitrine = $this->entite(Vitrine::class, ['etablissement' => $etabA]);
        $vitrine->setCanauxActifs(['app']);
        $this->em()->flush();
        $this->em()->clear();

        $client = static::createClient();
        $client->request('GET', 'http://piscine-a.fluvia-app.com/api/boutique/vitrine-courante');

        self::assertResponseStatusCodeSame(404, 'Canal `en_ligne` coupé : l’hôte ne sert rien.');
    }

    public function testUnHoteInconnuOuVideNeResoutPas(): void
    {
        foreach ([null, '', 'inexistant.fluvia-app.com', '.fluvia-app.com', 'localhost'] as $hote) {
            self::assertNull($this->resolveur()->resoudreParHote($hote), var_export($hote, true));
        }
    }

    /**
     * Le domaine vient de la configuration : sinon la résolution ne serait testable qu'en
     * production, donc jamais testée.
     */
    public function testLeDomaineDePlateformeEstConfigurable(): void
    {
        self::assertNull($this->resolveur('autre-marque.fr')->resoudreParHote('piscine-a.fluvia-app.com'));
        self::assertInstanceOf(Vitrine::class, $this->resolveur('autre-marque.fr')->resoudreParHote('piscine-a.autre-marque.fr'));
    }

    // ── LE POINT D'ENTRÉE PUBLIC ────────────────────────────────────────────────────────────────

    public function testLEndpointRendLaBoutiqueDeLHote(): void
    {
        $client = static::createClient();
        // ⚠ URL ABSOLUE : BrowserKit tire l'hote de l'URI, pas d'un en-tete `Host` ajoute a la
        // main. Poser l'en-tete laissait le provider voir `localhost`.
        $reponse = $client->request('GET', 'http://piscine-a.fluvia-app.com/api/boutique/vitrine-courante');

        self::assertResponseIsSuccessful();
        $donnees = $reponse->toArray();
        self::assertSame('piscine-a', $donnees['slug']);
        self::assertArrayHasKey('couleurs', $donnees);
        self::assertArrayNotHasKey('canauxActifs', $donnees, 'Aucune donnée interne ne sort par ici.');
        self::assertArrayNotHasKey('etablissement', $donnees);
    }

    public function testLEndpointRend404SurUnHoteInconnu(): void
    {
        $client = static::createClient();
        $client->request('GET', 'http://inexistant.fluvia-app.com/api/boutique/vitrine-courante');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Une boutique dépubliée est introuvable, pas « trouvée mais vide ».
     *
     * ⚠ Sans ce cas, la règle de publication aurait pu ne vivre que dans le listing public, et
     * l'hôte aurait servi une boutique fermée — le seul chemin par lequel personne ne regarde.
     */
    public function testUneBoutiqueDepublieeEstIntrouvableParSonHote(): void
    {
        $etabA = $this->entite(Etablissement::class, ['nom' => \App\DataFixtures\SocleFixtures::ETAB_A_NOM]);
        $etabA->setActif(false);
        $this->em()->flush();
        $this->em()->clear();

        $client = static::createClient();
        $client->request('GET', 'http://piscine-a.fluvia-app.com/api/boutique/vitrine-courante');

        self::assertResponseStatusCodeSame(404);
    }
}
