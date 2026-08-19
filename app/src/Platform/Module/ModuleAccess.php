<?php

declare(strict_types=1);

namespace App\Platform\Module;

use App\Fonctionnalite\Service\Fonctionnalites;
use App\Organisation\Entity\Etablissement;

/**
 * Activation à deux niveaux, par établissement (RG-PLAT-08).
 *
 * Un module est vendu comme un tout, mais tout ce qu'il contient ne s'active pas d'un bloc : un client
 * peut avoir le contrôle d'accès sans l'accès nocturne. D'où deux questions distinctes — *le module
 * est-il souscrit ?* et *cette fonctionnalité-là est-elle ouverte ?* — et non un seul booléen qui
 * forcerait à tout vendre ensemble.
 *
 * **Aucun schéma nouveau.** On s'appuie sur {@see Fonctionnalites}, déjà porteur de l'activation par
 * établissement. Le noyau ne recrée pas une table de droits à côté de celle qui existe : deux sources
 * de vérité sur « qui a droit à quoi » finiraient par diverger, et c'est toujours la mauvaise qui
 * répond en production.
 *
 * **Désactiver n'efface rien** (RG-PLAT-09) : seule l'exposition change. Un client qui réactive une
 * capacité retrouve ses données.
 */
final class ModuleAccess
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly Fonctionnalites $fonctionnalites,
    ) {
    }

    /**
     * L'établissement a-t-il souscrit la capacité qui porte ce module ?
     *
     * Une capacité jamais paramétrée répond `false` sans erreur (CA-5) : l'absence de ligne est une
     * réponse valide, pas un incident.
     */
    public function hasModule(Etablissement $etablissement, string $capability): bool
    {
        return $this->fonctionnalites->estActive($etablissement, $capability);
    }

    /**
     * Cette fonctionnalité est-elle ouverte pour cet établissement ?
     *
     * Trois refus, tous fermés :
     * 1. **Feature inconnue du registre** — aucun module ne la déclare. On refuse plutôt que de laisser
     *    passer : une faute de frappe dans un nom de feature donnerait sinon un accès, pas une erreur.
     * 2. **Module éteint** — une fonctionnalité d'un module non souscrit n'existe pas, quoi que dise la
     *    ligne d'activation. C'est le sens de « deux niveaux » : le second ne peut pas contourner le
     *    premier.
     * 3. **Feature non activée** pour cet établissement.
     *
     * L'inverse n'est pas vrai : module actif n'implique pas toutes ses features actives (RG-PLAT-08).
     */
    public function hasFeature(Etablissement $etablissement, string $feature): bool
    {
        $module = $this->moduleDeclarant($feature);

        if (!$module instanceof ModuleManifest) {
            return false;
        }

        if (!$this->hasModule($etablissement, $module->capability())) {
            return false;
        }

        return $this->fonctionnalites->estActive($etablissement, $feature);
    }

    /**
     * Le module qui déclare cette feature à son manifeste, s'il existe.
     *
     * Passer par le registre plutôt que par une convention de nommage : c'est le manifeste qui fait
     * foi sur ce qu'un module contient (D2), et lui seul rend le lien feature → module inspectable.
     */
    private function moduleDeclarant(string $feature): ?ModuleManifest
    {
        foreach ($this->registry->all() as $manifest) {
            if (\in_array($feature, $manifest->features(), true)) {
                return $manifest;
            }
        }

        return null;
    }
}
