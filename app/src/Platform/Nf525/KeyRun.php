<?php

declare(strict_types=1);

namespace App\Platform\Nf525;

/**
 * Intervalle d'UNE clé dans UNE chaîne NF525 : l'identifiant de clé (`null` = maillon sans identifiant,
 * antérieur à #361), la première et la dernière séquence où la chaîne le porte. Une chaîne = une liste
 * de `KeyRun`, un seul par identifiant. Jamais une clé : l'identifiant est une empreinte non réversible.
 * Échec FERMÉ : des bornes impossibles (séquence < 1, première > dernière) lèvent une exception.
 */
final readonly class KeyRun
{
    public ?string $keyId;

    public function __construct(?string $keyId, public int $first, public int $last)
    {
        if ($first < 1 || $last < $first) {
            throw new \InvalidArgumentException(sprintf('KeyRun : bornes invalides (%d, %d).', $first, $last));
        }
        // Même convention que `SealKeyring::check` : l'identifiant vide vaut « sans identifiant ».
        $this->keyId = ($keyId === '') ? null : $keyId;
    }
}
