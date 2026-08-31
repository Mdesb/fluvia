<?php

namespace App;

use App\Platform\DependencyInjection\Compiler\UuidAwareSearchFilterPass;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * UN CACHE COMPILE PAR JETON DE TEST.
     *
     * `test-stack.sh` isole le reseau, la base et le nom du conteneur Docker — mais toutes les
     * executions ecrivaient dans le meme `var/cache/test`, que `run` purge au demarrage. Deux
     * sessions simultanees se detruisaient donc le cache l'une l'autre, et l'une pouvait lire un
     * conteneur compile a moitie par l'autre.
     *
     * Constate le 31/08 : six tests en 404 « No route found » sur une route que `debug:router`
     * listait dans le MEME environnement et que la preproduction servait en 201. La trace etait
     * dans le journal de l'autre execution :
     *
     *     ValidationPassageHandler::__construct(): Argument #7 doit etre VersionSnapshotSequencer,
     *     VerdictBilletHandler donne, appele depuis var/cache/test/ContainerDYzwSUN/...
     *
     * ⚠ Un faux rouge de cette famille ne ressemble pas a un probleme d'environnement : il ressemble
     * a une regression du voisin. J'ai failli le lui renvoyer.
     *
     * ── LA GARDE, ET ELLE EST DOUBLE ────────────────────────────────────────────────────────────
     *
     * Environnement `test` ET `TEST_TOKEN` non vide. La production ne pose ni l'un ni l'autre : elle
     * ne peut pas atteindre cette branche, meme par accident de configuration.
     *
     * Le jeton est filtre avant d'entrer dans un chemin — il vient d'un argument de ligne de
     * commande, et un jeton fantaisiste ne doit pas pouvoir designer un repertoire ailleurs.
     */
    public function getCacheDir(): string
    {
        $jeton = $_SERVER['TEST_TOKEN'] ?? $_ENV['TEST_TOKEN'] ?? null;

        if ($this->environment === 'test' && \is_string($jeton) && $jeton !== '') {
            $sur = preg_replace('/[^A-Za-z0-9_-]/', '', $jeton);

            if ($sur !== '' && $sur !== null) {
                return $this->getProjectDir() . '/var/cache/test-' . $sur;
            }
        }

        return parent::getCacheDir();
    }

    protected function build(ContainerBuilder $container): void
    {
        // La priorité négative place la passe après celles d'API Platform, qui créent les services
        // de filtre à partir des attributs `#[ApiFilter]`. Trop tôt, il n'y aurait rien à remplacer
        // — et rien ne le signalerait : un filtre non substitué rend une liste vide, pas une erreur.
        $container->addCompilerPass(new UuidAwareSearchFilterPass(), priority: -100);
    }
}
