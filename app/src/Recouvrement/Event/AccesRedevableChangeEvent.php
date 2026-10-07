<?php

declare(strict_types=1);

namespace App\Recouvrement\Event;

/**
 * Dispatché par `PropagationAccesHandler` à chaque bascule d'accès décidée par le moteur de
 * recouvrement (valide/dévalidé). Permet à la verticale propriétaire du contrat de tenir à jour sa
 * propre projection d'accès (ex. `App\Membership\Entity\StatutAccesFitness.actif/motifInactivite`) — le
 * `DroitAcces` L3 est déjà écrit par `PropagationAccesHandler` lui-même avant ce dispatch.
 *
 * **C12 (RG-PLAT-03) — établissement porté pour rendre l'événement pontable.** `etablissementId` est
 * l'UUID (chaîne, scalaire — RG-PLAT-04) de l'établissement du `DroitAcces` résolu, ou `null` si aucun
 * droit n'a pu être résolu pour ce redevable. Il permet au pont d'événements legacy
 * (`App\Platform\Event\Legacy\LegacyEventBridge`) de dériver le tenant (D6) sans importer le code de
 * `Recouvrement` : l'événement n'était volontairement pas ponté jusqu'ici faute d'établissement. Le
 * câblage du pont lui-même (nouvel événement de contrat + consommateur, garde-fou n°6) reste côté
 * `Platform`, hors périmètre de ce lot.
 */
final class AccesRedevableChangeEvent
{
    public function __construct(
        public readonly string $typeRedevable,
        public readonly string $referenceRedevable,
        public readonly bool $actif,
        public readonly ?string $etablissementId = null,
    ) {
    }
}
