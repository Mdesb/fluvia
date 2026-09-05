<?php

declare(strict_types=1);

namespace App\Tests\Website;

use App\Tests\SocleApiTestCase;
use App\Website\Service\ModuleCatalog;
use App\Website\Service\ModuleFamilies;

/**
 * Le rangement éditorial des modules — et surtout, la garantie qu'aucun ne s'en échappe.
 *
 * **Ce qu'on protège.** `ModuleFamilies` range les modules par usage pour la page de tarifs. La
 * correspondance est écrite à la main, donc elle vieillit : le jour où une capacité est ajoutée au
 * catalogue, elle n'y figure pas.
 *
 * Le réflexe — n'afficher que ce qu'on sait ranger — produirait le pire défaut possible sur une
 * page de prix : un module **vendable et facturé** absent de la page qui liste ce qu'on vend.
 * Personne ne le verrait, puisqu'il ne manquerait nulle part. C'est une absence, et une absence ne
 * crie pas.
 */
final class ModuleFamiliesTest extends SocleApiTestCase
{
    /**
     * ⚠ **LE TEST QUI COMPTE : L'ÉGALITÉ DES COMPTES, PAS LE CONTENU DE LA TABLE.**
     *
     * Vérifier que la table contient telle ou telle clé ne prouverait rien — elle serait vraie le
     * jour où on l'écrit et fausse le lendemain. On vérifie que la somme des modules rangés égale le
     * nombre de modules vendables. Cette égalité tombe d'elle-même dès qu'un module apparaît sans
     * être rangé, quel qu'il soit et sans qu'on ait à le nommer ici.
     */
    public function testAucunModuleVendableNeSePerdEnChemin(): void
    {
        $catalogue = static::getContainer()->get(ModuleCatalog::class);
        $familles = new ModuleFamilies();

        $modules = $catalogue->modules();
        self::assertNotSame([], $modules, 'témoin : le catalogue doit porter des modules, sinon ce test ne mesure rien');

        $clesConnues = array_column($familles->familles(), 'cle');

        $ranges = 0;
        foreach ($modules as $module) {
            $famille = $familles->pour($module['code']);

            self::assertContains(
                $famille,
                $clesConnues,
                sprintf('le module « %s » tombe dans une famille qui ne sera pas affichée', $module['code']),
            );

            ++$ranges;
        }

        self::assertSame(
            \count($modules),
            $ranges,
            'un module vendable ne se range nulle part : il serait facturé sans figurer sur la page des tarifs',
        );
    }

    /**
     * ⚠ **LE REFUGE PROTEGE LA PAGE ; CE TEST FORCE LA DECISION.**
     *
     * Sans lui, un module ajouté au catalogue atterrirait dans « Piloter » et y resterait — rangé
     * nulle part, mais affiché quelque part, donc invisible comme défaut. C'est arrivé pendant
     * l'écriture même de ce service : trois modules (hébergement, restauration, séjours) y
     * dormaient, et seule la mutation du refuge les a fait apparaître.
     *
     * On exige donc que le refuge ne serve à personne AUJOURD'HUI. Il reste en place pour que la
     * page ne perde jamais un module en production ; ce test fait que la table, elle, est tenue à
     * jour sciemment plutôt que par accident.
     */
    public function testAucunModuleNeDortDansLeRefuge(): void
    {
        $catalogue = static::getContainer()->get(ModuleCatalog::class);
        $familles = new ModuleFamilies();
        $table = $familles->pourLeNavigateur()['rangement'];

        $oublies = [];
        foreach ($catalogue->modules() as $module) {
            if (!array_key_exists($module['code'], $table)) {
                $oublies[] = $module['code'];
            }
        }

        self::assertSame(
            [],
            $oublies,
            'ces modules tombent dans le refuge : rangez-les dans ModuleFamilies plutot que de les '
                .'laisser echouer dans la derniere famille',
        );
    }

    /**
     * **Le refuge tient, et c'est ce qui rend l'égalité ci-dessus vraie pour toujours.**
     *
     * Sans lui, un code inconnu rendrait `null` et le compte tomberait — ce test-ci prouve que la
     * garantie ne repose pas sur la bonne tenue de la table, mais sur le comportement du service.
     */
    public function testUnCodeInconnuTombeDansLeRefugePlutotQueNullePart(): void
    {
        $familles = new ModuleFamilies();

        $famille = $familles->pour('module_qui_nexiste_pas_encore');

        self::assertContains($famille, array_column($familles->familles(), 'cle'));
    }

    /**
     * La table passée au navigateur est celle du serveur, pas une copie.
     *
     * `tarifs.js` lit les prix du catalogue à l'exécution et a besoin de savoir où poser chaque
     * option. Deux tables — une en PHP, une en JavaScript — divergeraient au premier module ajouté,
     * et la page afficherait un rangement différent de celui qu'on croit avoir écrit.
     */
    public function testLaTablePasseeAuNavigateurEstCelleDuServeur(): void
    {
        $familles = new ModuleFamilies();

        $pourLeNavigateur = $familles->pourLeNavigateur();

        self::assertSame($familles->familles(), $pourLeNavigateur['familles']);
        self::assertArrayHasKey('rangement', $pourLeNavigateur);
        self::assertContains($pourLeNavigateur['refuge'], array_column($familles->familles(), 'cle'));

        foreach ($pourLeNavigateur['rangement'] as $code => $cle) {
            self::assertSame(
                $familles->pour($code),
                $cle,
                sprintf('la table donnée au navigateur range « %s » ailleurs que le serveur', $code),
            );
        }
    }
}
