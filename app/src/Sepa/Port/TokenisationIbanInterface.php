<?php

declare(strict_types=1);

namespace App\Sepa\Port;

use App\Sepa\Dto\TokenIban;

/**
 * Port de tokenisation IBAN (§2.2 du plan, repris de `App\Sport\Sepa\Port\TokenisationIbanInterface`).
 * L'IBAN en clair n'est **jamais** mappé Doctrine ni exposé en API — seul le token (non réversible
 * trivialement) et les 4 derniers chiffres sont persistés.
 */
interface TokenisationIbanInterface
{
    /** Tokenise un IBAN en clair reçu en entrée d'un processor ; l'IBAN clair n'est jamais persisté. */
    public function tokeniser(string $ibanClair): TokenIban;
}
