<?php

namespace App;

use App\Platform\DependencyInjection\Compiler\UuidAwareSearchFilterPass;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    protected function build(ContainerBuilder $container): void
    {
        // La priorité négative place la passe après celles d'API Platform, qui créent les services
        // de filtre à partir des attributs `#[ApiFilter]`. Trop tôt, il n'y aurait rien à remplacer
        // — et rien ne le signalerait : un filtre non substitué rend une liste vide, pas une erreur.
        $container->addCompilerPass(new UuidAwareSearchFilterPass(), priority: -100);
    }
}
