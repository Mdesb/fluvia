<?php

declare(strict_types=1);

namespace App\Vente\Service;

/**
 * Une autre tentative tient la vente : rien n'a été encaissé par cette demande (G-3, G-6).
 *
 * Rendue en 409 avec un code lisible par une machine — le message d'une exception ne traverse pas
 * toujours la production, et l'écran (lot 3) doit distinguer les deux cas :
 * - `payment_in_progress` : l'effet est en cours ; rejouer plus tard, avec la même clé ;
 * - `payment_outcome_unknown` : le terminal a pu débiter ; rien ne repart avant une déclaration (Q-A1).
 */
final class PaymentAttemptConflict extends \RuntimeException
{
    public const IN_PROGRESS = 'payment_in_progress';
    public const OUTCOME_UNKNOWN = 'payment_outcome_unknown';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function inProgress(): self
    {
        return new self(self::IN_PROGRESS, 'Un règlement est déjà en cours sur cette vente ; cette demande n\'a rien encaissé. '
            . 'Attendez son issue, puis relisez le reste dû avant d\'encaisser de nouveau.');
    }

    public static function outcomeUnknown(): self
    {
        return new self(self::OUTCOME_UNKNOWN, 'Le terminal n\'a pas rendu d\'issue pour un règlement de cette vente : la carte a peut-être été débitée. '
            . 'Rien ne s\'encaisse sur cette vente tant que ce qu\'affiche le terminal n\'a pas été déclaré.');
    }
}
