<?php

declare(strict_types=1);

namespace App\Platform\Nf525;

/**
 * RÈGLE D'ORDRE DES CLÉS (#369), FORME PARCOURS : une clé qu'une chaîne a quittée n'y revient jamais, et
 * un maillon sans identifiant ne vient jamais après un maillon qui en porte un.
 *
 * Alimenté lien par lien, par numéro de séquence croissant, pendant la boucle d'un vérificateur : état
 * minimal (clé courante, clés quittées), aucune requête. C'est la MÊME définition que `SealKeyOrder`
 * (forme intervalles) ; `SealKeyOrderTest` exige qu'elles s'accordent sur toutes les petites suites.
 */
final class SealKeyOrderTracker
{
    private ?string $current = null;
    private ?string $lastNonNull = null;

    /** @var array<string, true> clés que la chaîne a quittées */
    private array $left = [];

    private int $lastSequence = 0;

    /**
     * Enregistre le maillon suivant ; rend la régression qu'il constitue, ou `null`. Les séquences doivent
     * être STRICTEMENT croissantes (échec fermé : `\InvalidArgumentException` sinon). Mémoire : un
     * identifiant au plus par clé distincte rencontrée, soit O(n) dans le pire cas (n maillons forgés).
     */
    public function feed(int $sequence, ?string $keyId): ?KeyRegression
    {
        if ($sequence <= $this->lastSequence) {
            throw new \InvalidArgumentException(sprintf('Séquences non croissantes : %d après %d.', $sequence, $this->lastSequence));
        }
        $this->lastSequence = $sequence;
        $keyId = ($keyId === '') ? null : $keyId;
        $regression = null;

        if ($keyId === null) {
            if ($this->lastNonNull !== null) {
                $regression = new KeyRegression(KeyRegression::NULL_AFTER_KEY, $sequence, null, $this->lastNonNull);
            }
        } elseif (isset($this->left[$keyId])) {
            $regression = new KeyRegression(KeyRegression::RETURNED_KEY, $sequence, $keyId, $this->current);
        }

        if ($this->current !== null && $this->current !== $keyId) {
            $this->left[$this->current] = true;
        }
        $this->current = $keyId;
        if ($keyId !== null) {
            $this->lastNonNull = $keyId;
        }

        return $regression;
    }
}
