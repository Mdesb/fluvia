<?php

declare(strict_types=1);

namespace App\Vente\Service;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Lit le corps JSON de la requête courante sous forme de tableau associatif. Mutualise le décodage
 * pour les opérations métier (State Processors input:false) qui portent leur propre payload.
 */
final class LecteurCorps
{
    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    /** @return array<string, mixed> */
    public function corps(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return [];
        }
        $contenu = $request->getContent();
        if ($contenu === '') {
            return [];
        }
        $decode = json_decode($contenu, true);

        return \is_array($decode) ? $decode : [];
    }
}
