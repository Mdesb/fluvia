<?php

declare(strict_types=1);

namespace App\Vente\Tpe;

use App\Vente\Enum\StatutTPE;

/**
 * Résultat d'une transaction sur un terminal de paiement (US-L2-07). Seul un statut « accepte »
 * crée un règlement ; refus/annulation/timeout laissent le reste dû inchangé.
 */
final class ResultatTpe
{
    public function __construct(
        public readonly StatutTPE $statut,
        public readonly ?string $reference = null,
    ) {
    }

    public function estAccepte(): bool
    {
        return $this->statut === StatutTPE::Accepte;
    }
}
