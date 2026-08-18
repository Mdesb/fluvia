<?php

declare(strict_types=1);

namespace App\Autorisation\Service;

use App\Securite\Entity\Utilisateur;
use Symfony\Component\Uid\Uuid;

/**
 * Contrat d'entrée de `ServiceAutorisation::evaluer()` (§2.1 plan). L'appelant (M2 ou tout futur
 * module) transmet des scalaires déjà extraits de ses propres entités — `App\Autorisation` ne
 * dépend jamais de `App\Vente`/`App\Caisse`.
 *
 * NB : `cibleEtablissementId` est ici nullable (contrairement à la signature stricte esquissée au
 * §2.1 du plan) — l'exemple d'intégration du plan lui-même (§6.1/§6.2) transmet
 * `$data->getEtablissement()?->getId()`, potentiellement `null` au niveau du type PHP de
 * `Vente::getEtablissement()`. Rendu nullable pour rester fidèle à l'usage réel décrit par le plan.
 */
final readonly class RequeteAutorisation
{
    public function __construct(
        public string $operationCode,
        public Utilisateur $utilisateur,
        public string $montant,
        public string $cibleType,
        public Uuid $cibleId,
        public ?Uuid $cibleEtablissementId = null,
        public ?Uuid $cibleSessionOperateurId = null,
        public ?Uuid $cibleSessionRegisseurId = null,
        public ?Uuid $jetonRejeu = null,
    ) {
    }
}
