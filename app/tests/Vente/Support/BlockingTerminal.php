<?php

declare(strict_types=1);

namespace App\Tests\Vente\Support;

use App\Vente\Tpe\TerminalAttempt;
use App\Vente\Tpe\TerminalPaiementInterface;

/**
 * Le terminal simulé, qui tarde à répondre dans l'autre processus d'un test de concurrence : sa
 * réponse est connue, mais elle ne revient qu'à la levée de la barrière. Partout ailleurs, il laisse
 * passer (`SettlementBarrier`). Câblé en `when@test` sur l'alias `TerminalPaiementInterface`, pour que
 * `TpeMock` reste ce que les tests vont chercher par son nom.
 *
 * Le terminal simulé livre son issue PENDANT `demarrer()` (port asynchrone) : la barrière se tient
 * donc AVANT, la tentative encore `pending` — là où l'ancien terminal synchrone attendait.
 */
final class BlockingTerminal implements TerminalPaiementInterface
{
    public function __construct(private readonly TerminalPaiementInterface $inner)
    {
    }

    public function connector(): string
    {
        return $this->inner->connector();
    }

    public function staleAfterSeconds(): int
    {
        return $this->inner->staleAfterSeconds();
    }

    public function demarrer(TerminalAttempt $tentative): void
    {
        SettlementBarrier::hold('terminal');
        $this->inner->demarrer($tentative);
    }

    public function annuler(TerminalAttempt $tentative): void
    {
        $this->inner->annuler($tentative);
    }
}
