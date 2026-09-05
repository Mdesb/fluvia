<?php

declare(strict_types=1);

namespace App\Tests\Platform;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Platform\DataFixtures\PurgeurRefusant;
use App\Tests\SchemaDuHarnais;
use Doctrine\Bundle\FixturesBundle\Loader\SymfonyFixturesLoader;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\ORM\EntityManagerInterface;

/**
 * TOUTES LES CLASSES DE DÉMONSTRATION SE CHARGENT ENSEMBLE — ce que rien ne vérifiait.
 *
 * ── LE TROU, ET IL EST STRUCTUREL ───────────────────────────────────────────────────────────────
 *
 * Chaque cas de test déclare une LISTE EXPLICITE des fixtures dont il a besoin, et ne charge que
 * celles-là. `app:demo:charger`, lui, les charge TOUTES — quarante classes. Aucun test ne parcourait
 * donc l'ensemble : une classe de démonstration pouvait être cassée sans qu'une seule suite tombe,
 * et la préproduction le découvrait au chargement.
 *
 * ⚠ CE N'EST PAS UNE CRAINTE, C'EST CE QUI VIENT D'ARRIVER. Le 06/09, une fixture neuve importait
 * `App\Reservation\Entity\Beneficiaire` — la classe vit dans `App\Crm\Entity`. Le build, les 53
 * garde-fous et SEPT suites de tests sont passés au vert ; `app:demo:charger` a échoué au premier
 * essai sur la préproduction.
 *
 * ── POURQUOI CE TEST NE DUPLIQUE PAS `app:demo:charger` ─────────────────────────────────────────
 *
 * Il emprunte le même chemin : le même chargeur, le même exécuteur, le même mode additif, et un
 * purgeur qui REFUSE. S'ils divergeaient, ce test deviendrait vert pour une raison sans rapport avec
 * la commande qu'il protège — le défaut qu'on cherche justement à empêcher.
 *
 * ⚠ ET IL EXIGE UN TÉMOIN POSITIF. Un chargeur qui ne rendrait aucune classe ferait passer ce test
 * sans rien avoir chargé : c'est la vacuité la plus facile à écrire et la plus difficile à voir.
 */
final class DemoFixturesLoadTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    public function testLesQuaranteClassesDeDemonstrationSeChargentEnsemble(): void
    {
        $container = static::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();
        SchemaDuHarnais::reinitialiser($em);

        // ⚠ PAR SON IDENTIFIANT DE SERVICE, PAS PAR SON NOM DE CLASSE. Le conteneur de test n'expose
        // pas `SymfonyFixturesLoader::class` : le bundle l'enregistre sous `doctrine.fixtures.loader`,
        // et c'est cet identifiant que le conteneur connaît.
        /** @var SymfonyFixturesLoader $chargeur */
        $chargeur = $container->get('doctrine.fixtures.loader');
        $fixtures = $chargeur->getFixtures();

        // Le témoin positif : sans lui, un chargeur vide rendrait ce test vert sans rien prouver.
        self::assertGreaterThan(
            30,
            \count($fixtures),
            'Le chargeur doit rendre les classes de démonstration, sinon ce test ne mesure rien.',
        );

        // ⚠ MODE ADDITIF ET PURGEUR QUI REFUSE — exactement ce que fait `app:demo:charger`. Un
        // exécuteur qui purgerait viderait la base du harnais, et le test suivant partirait sur un
        // schéma vide sans que rien ne le dise.
        $executeur = new ORMExecutor($em, new PurgeurRefusant('test'));
        $executeur->execute($fixtures, true);

        // Deux chargements successifs ne doivent rien dupliquer : c'est la promesse
        // d'idempotence sur laquelle repose `app:demo:charger`, et elle ne se vérifie qu'en
        // rechargeant.
        $executeur->execute($fixtures, true);

        // Ce que ce test prouve tient dans ce qui NE s'est pas produit : aucune classe n'a levé, ni
        // au premier chargement ni au second. L'assertion de comptage plus haut est là pour qu'un
        // chargeur vide ne puisse pas prétendre à la même réussite.
        self::assertNotSame([], $fixtures);
    }
}
