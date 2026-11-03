<?php

declare(strict_types=1);

namespace App\Tests\Vente\Support;

use Doctrine\ORM\Event\PostLoadEventArgs;

/**
 * Glisse le geste d'une AUTRE connexion au moment précis où une entité est chargée (ou relue) : là
 * où le chemin de l'issue a lu la tentative, mais n'a pas encore ouvert sa transaction. C'est la
 * fenêtre d'une course réelle (relecture du lot TPE, D1) rendue déterministe. Désarmé tant qu'un test
 * ne l'arme pas ; ne joue qu'une fois.
 */
final class InterleaveOnLoad
{
    /** @var class-string|null */
    private ?string $on = null;

    /** @var (\Closure(): void)|null */
    private ?\Closure $then = null;

    /** @param class-string $class */
    public function arm(string $class, \Closure $then): void
    {
        $this->on = $class;
        $this->then = $then;
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        if ($this->on === null || !is_a($args->getObject(), $this->on)) {
            return;
        }
        $then = $this->then;
        $this->on = $this->then = null;
        $then?->__invoke();
    }
}
