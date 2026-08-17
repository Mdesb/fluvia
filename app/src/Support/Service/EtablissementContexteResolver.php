<?php

declare(strict_types=1);

namespace App\Support\Service;

use App\Securite\Service\ContexteEtablissement;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Résout l'établissement de « contexte » pour la KB publique (§2/§4 plan-support.md) : paramètre de
 * requête `etablissement` (visiteur anonyme, non fiable, n'élargit que la visibilité d'articles déjà
 * publics — Risque n°6) prioritaire sur l'en-tête `X-Etablissement` (rédacteur/agent back-office).
 */
final class EtablissementContexteResolver
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function resoudre(): ?Uuid
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request !== null) {
            $valeur = $request->query->get('etablissement');
            if (\is_string($valeur) && $valeur !== '') {
                $candidat = basename($valeur);
                if (Uuid::isValid($candidat)) {
                    return Uuid::fromString($candidat);
                }
            }
        }

        return $this->contexte->idActif();
    }
}
