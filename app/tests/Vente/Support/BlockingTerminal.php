<?php

declare(strict_types=1);

namespace App\Tests\Vente\Support;

use App\Caisse\Entity\PointDeVente;
use App\Vente\Tpe\ResultatTpe;
use App\Vente\Tpe\TerminalPaiementInterface;

/**
 * Le terminal simulé, qui tarde à répondre dans l'autre processus d'un test de concurrence : sa
 * réponse est connue, mais elle ne revient qu'à la levée de la barrière. Partout ailleurs, il laisse
 * passer (`SettlementBarrier`). Câblé en `when@test` sur l'alias `TerminalPaiementInterface`, pour que
 * `TpeMock` reste ce que les tests vont chercher par son nom.
 */
final class BlockingTerminal implements TerminalPaiementInterface
{
    public function __construct(private readonly TerminalPaiementInterface $inner)
    {
    }

    public function demander(PointDeVente $pointDeVente, string $montant): ResultatTpe
    {
        $resultat = $this->inner->demander($pointDeVente, $montant);
        SettlementBarrier::hold('terminal');

        return $resultat;
    }
}
