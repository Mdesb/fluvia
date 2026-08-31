<?php

declare(strict_types=1);

namespace App\Tests\Platform;

use App\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * CHAQUE JETON DE TEST A SON CONTENEUR COMPILE — ET LA PRODUCTION N'EST JAMAIS DÉPLACÉE.
 *
 * ── LE DÉFAUT QUE CE FILET GARDE FERMÉ ──────────────────────────────────────────────────────────
 *
 * `test-stack.sh` isole le réseau, la base et le nom du conteneur Docker. Il n'isolait pas
 * `var/cache/test`, partagé par toutes les exécutions et **purgé par chacune au démarrage**. Deux
 * sessions simultanées se détruisaient donc le cache l'une l'autre, et l'une pouvait lire un
 * conteneur compilé à moitié par l'autre.
 *
 * Ce que ça donnait le 31/08 : six tests en 404 « No route found » sur une route que
 * `debug:router` listait dans le MÊME environnement et que la préproduction servait en 201. La
 * trace était dans le journal de l'AUTRE exécution :
 *
 *     ValidationPassageHandler::__construct(): Argument #7 doit être VersionSnapshotSequencer,
 *     VerdictBilletHandler donné, appelé depuis var/cache/test/ContainerDYzwSUN/...
 *
 * ⚠ Un faux rouge de cette famille ne ressemble pas à un défaut d'environnement : il ressemble à
 * une régression du voisin. J'ai failli renvoyer ces six échecs à la session qui avait écrit le
 * test, comme « non expliqués ».
 *
 * ── LES QUATRE CAS, ET LES TROIS DERNIERS SONT LES PLUS IMPORTANTS ──────────────────────────────
 *
 * Le premier dit ce que la surcharge fait. Les trois autres disent ce qu'elle **ne fait pas** —
 * c'est-à-dire tout ce qui la sépare d'un déplacement sauvage du cache de production. Une garde
 * qu'on n'éprouve que dans le sens où elle agit est indiscernable d'une garde trop large.
 */
final class CacheParJetonTest extends TestCase
{
    private ?string $jetonDOrigine = null;

    protected function setUp(): void
    {
        $this->jetonDOrigine = isset($_SERVER['TEST_TOKEN']) ? (string) $_SERVER['TEST_TOKEN'] : null;
    }

    protected function tearDown(): void
    {
        if ($this->jetonDOrigine === null) {
            unset($_SERVER['TEST_TOKEN'], $_ENV['TEST_TOKEN']);

            return;
        }

        $_SERVER['TEST_TOKEN'] = $this->jetonDOrigine;
        $_ENV['TEST_TOKEN'] = $this->jetonDOrigine;
    }

    private function repertoire(string $environnement, ?string $jeton): string
    {
        if ($jeton === null) {
            unset($_SERVER['TEST_TOKEN'], $_ENV['TEST_TOKEN']);
        } else {
            $_SERVER['TEST_TOKEN'] = $jeton;
            $_ENV['TEST_TOKEN'] = $jeton;
        }

        return basename((new Kernel($environnement, true))->getCacheDir());
    }

    /** Le cas nominal : deux jetons, deux répertoires. */
    public function testDeuxJetonsNePartagentPasLeurConteneurCompile(): void
    {
        self::assertSame('test-alpha', $this->repertoire('test', 'alpha'));
        self::assertSame('test-beta', $this->repertoire('test', 'beta'));
    }

    /**
     * ⚠ LE CAS QUI PROTÈGE LA PRODUCTION. Un `TEST_TOKEN` égaré dans l'environnement d'un serveur
     * ne doit pas déplacer son cache : ce serait une panne au démarrage, sur la seule machine où
     * elle coûte quelque chose.
     */
    public function testUnJetonEgareNeDeplacePasLeCacheDeProduction(): void
    {
        self::assertSame('prod', $this->repertoire('prod', 'intrus'));
        self::assertSame('dev', $this->repertoire('dev', 'intrus'));
    }

    /** Sans jeton, on retombe exactement sur le comportement d'avant. */
    public function testSansJetonLeCheminHistoriqueEstConserve(): void
    {
        self::assertSame('test', $this->repertoire('test', null));
    }

    /**
     * Le jeton vient d'un argument de ligne de commande et entre dans un CHEMIN. Un jeton fantaisiste
     * ne doit pas pouvoir désigner un répertoire ailleurs qu'où on l'attend.
     */
    public function testUnJetonFantaisisteNeSortPasDuRepertoireDeCache(): void
    {
        self::assertSame('test-etcpasswd', $this->repertoire('test', '../../etc/passwd'));
        self::assertSame('test', $this->repertoire('test', '///'), 'Un jeton vidé par le filtrage retombe sur le chemin historique.');
    }
}
