<?php

declare(strict_types=1);

namespace App\Tests\Fonctionnalite\Unit;

use App\Fonctionnalite\Config\ActivityCapabilities;
use App\Fonctionnalite\Enum\EstablishmentActivity;
use App\Fonctionnalite\Enum\CapabilityCoverage;
use App\Fonctionnalite\Enum\CapaciteCode;
use PHPUnit\Framework\TestCase;

/**
 * Le filet contre le defaut qui a mis l'accueil a terre le 06/09.
 *
 * ⚠ **CE QUI EST ARRIVE, ET QUE CE TEST EXISTE POUR REJOUER.** `CapaciteCode::Connecteurs` a ete
 * ajoutee a l'enumeration sans son descripteur dans le `match` sans branche par defaut de
 * `CatalogueCapacites`. Tout appel a `toutes()` levait `UnhandledMatchError` : la page d'accueil du
 * site public et l'ecran « Site vitrine » rendaient 500, pendant que `/metiers` et `/blog`
 * repondaient normalement — ils ne passent pas par le catalogue complet. Deux surfaces seulement
 * criaient : d'ou le risque de croire le site sain.
 *
 * `ActivityCapabilities` porte deux `match` de la meme famille. Ce test les fait lever EN
 * INTEGRATION CONTINUE, avant la fusion, plutot qu'en production.
 *
 * ⚠ **LA SOMME EST L'ASSERTION PRINCIPALE.** Elle ne verifie pas un comportement, elle verifie une
 * COUVERTURE : que les vingt-six capacites ont chacune recu exactement un regime. C'est la seule
 * forme qui distingue « classee ailleurs » de « oubliee ».
 */
final class ActivityCapabilitiesTest extends TestCase
{
    public function testLesVingtSixCapacitesSontToutesClassees(): void
    {
        $parRegime = [];

        foreach (CapaciteCode::cases() as $code) {
            // ⚠ Si un jour une capacite neuve n'est pas classee, cet appel leve `UnhandledMatchError`
            //   et le test est ROUGE. C'est le comportement recherche : bruyant, en CI, avant la
            //   fusion — pas silencieux, en production, sur la page d'accueil.
            $parRegime[ActivityCapabilities::coverageOf($code)->name][] = $code->value;
        }

        $total = array_sum(array_map(\count(...), $parRegime));

        self::assertSame(
            \count(CapaciteCode::cases()),
            $total,
            'Chaque capacité doit recevoir exactement un régime — ni zéro, ni deux.',
        );

        // Un temoin par regime : un classement qui viderait une categorie passerait la somme.
        self::assertNotEmpty($parRegime[CapabilityCoverage::ByActivity->name] ?? []);
        self::assertNotEmpty($parRegime[CapabilityCoverage::Common->name] ?? []);
        self::assertNotEmpty($parRegime[CapabilityCoverage::OwnRule->name] ?? []);
        self::assertNotEmpty($parRegime[CapabilityCoverage::Vertical->name] ?? []);
    }

    /**
     * ⚠ LE COEUR DU CONTROLE : la table s'ecrit dans UN sens, se lit dans les DEUX.
     *
     * `of()` dit ce qu'une activite allume ; `coverageOf()` dit si une capacite est servie par une
     * activite. Si les deux divergeaient, une capacite pourrait etre annoncee « servie » sans
     * qu'aucune activite ne la serve — ou l'inverse, etre allumee par une activite tout en etant
     * classee « regle propre », donc jamais suggeree alors qu'elle l'est.
     */
    public function testLUnionDesActivitesEstExactementLeRegimeParActivite(): void
    {
        $union = [];

        foreach (EstablishmentActivity::cases() as $activity) {
            $codes = ActivityCapabilities::of($activity);

            self::assertNotEmpty(
                $codes,
                sprintf('L\'activité « %s » n\'allume rien : une activité sans capacité ne suggère rien.', $activity->value),
            );

            foreach ($codes as $code) {
                $union[$code->value] = true;
            }
        }

        $classeesParActivite = [];

        foreach (CapaciteCode::cases() as $code) {
            if (CapabilityCoverage::ByActivity === ActivityCapabilities::coverageOf($code)) {
                $classeesParActivite[$code->value] = true;
            }
        }

        ksort($union);
        ksort($classeesParActivite);

        self::assertSame(
            array_keys($classeesParActivite),
            array_keys($union),
            'La table des activités et le classement des capacités décrivent le même ensemble, ou ils mentent l\'un sur l\'autre.',
        );
    }

    /**
     * Les cinq capacites qui portent un nom de verticale sont classees comme telles — et par
     * delegation, pas par recopie. Si `CatalogueCapacites::estVerticale()` changeait d'avis, ce
     * test suivrait ; une liste recopiee ici ne suivrait pas.
     */
    public function testLesVerticalesNeSontPasDesModulesSuggerables(): void
    {
        foreach (['piscine', 'sport', 'padel', 'patinoire', 'musee'] as $valeur) {
            $code = CapaciteCode::from($valeur);

            self::assertSame(
                CapabilityCoverage::Vertical,
                ActivityCapabilities::coverageOf($code),
                sprintf('« %s » nomme une verticale : ce n\'est pas un module vendable.', $valeur),
            );
        }
    }

    public function testAucuneActiviteNeSuggereUneRegleProprePreOuUneVerticale(): void
    {
        $suggeres = ActivityCapabilities::modulesFor(EstablishmentActivity::cases());

        foreach ($suggeres as $valeur) {
            $regime = ActivityCapabilities::coverageOf(CapaciteCode::from($valeur));

            self::assertNotSame(
                CapabilityCoverage::OwnRule,
                $regime,
                sprintf('« %s » est une règle propre : elle ne se suggère pas, elle se décide.', $valeur),
            );
            self::assertNotSame(
                CapabilityCoverage::Vertical,
                $regime,
                sprintf('« %s » nomme une verticale : elle ne se vend pas comme module.', $valeur),
            );
        }
    }

    /**
     * ⚠ SANS AUCUNE ACTIVITE, ON N'OBTIENT PAS RIEN : on obtient le socle.
     *
     * C'est l'argument deja ecrit dans `PresetVerticale::COMMUNES` — tout exploitant tient des
     * comptes et vend des marchandises. Un metier sans activite declaree ne doit pas ouvrir une
     * plateforme vide, ce qui est exactement le defaut n°1 du referentiel des faits.
     */
    public function testSansActiviteOnObtientLeSocleEtRienDAutre(): void
    {
        $socle = [];

        foreach (CapaciteCode::cases() as $code) {
            if (CapabilityCoverage::Common === ActivityCapabilities::coverageOf($code)) {
                $socle[] = $code->value;
            }
        }

        $obtenu = ActivityCapabilities::modulesFor([]);

        sort($socle);
        sort($obtenu);

        self::assertSame($socle, $obtenu);
        self::assertNotEmpty($obtenu, 'Le socle ne peut pas être vide : ce serait une plateforme sans rien.');
    }

    public function testUneActiviteConnueSuggereSesModulesEtLeSocle(): void
    {
        $obtenu = ActivityCapabilities::modulesFor([EstablishmentActivity::EquipmentRental]);

        self::assertContains(CapaciteCode::LocationMateriel->value, $obtenu);
        self::assertContains(CapaciteCode::Casiers->value, $obtenu);
        self::assertContains(CapaciteCode::Comptabilite->value, $obtenu, 'Le socle accompagne toute activité.');
        self::assertNotContains(CapaciteCode::Poss->value, $obtenu, 'Le POSS est une règle propre, jamais suggérée.');
    }
}
