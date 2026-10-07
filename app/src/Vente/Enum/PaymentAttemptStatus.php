<?php

declare(strict_types=1);

namespace App\Vente\Enum;

/**
 * Où en est une tentative de règlement (G-3, G-6 du ticket opposable).
 *
 * Deux statuts tiennent la vente — aucun autre règlement n'y passe : `Pending` (l'effet est en cours)
 * et `Unresolved` (le terminal a pu débiter, personne ne sait). Les trois autres sont des issues
 * définitives et la libèrent.
 */
enum PaymentAttemptStatus: string
{
    /** Écrite et validée en base AVANT l'effet : le terminal ou le porte-monnaie peut être en train d'agir. */
    case Pending = 'pending';

    /** Le règlement est écrit, dans la même transaction que ce statut. */
    case Accepted = 'accepted';

    /** Le terminal a refusé, ou le porteur a annulé : aucun argent n'a bougé. Rejouée, la clé rend ce refus. */
    case Refused = 'refused';

    /** Rien n'a bougé (demande refusée, ou débit annulé avec l'écriture) : la même demande peut être rejouée. */
    case Failed = 'failed';

    /** Timeout, réponse perdue, processus interrompu : rien ne repart au terminal avant une déclaration (Q-A1). */
    case Unresolved = 'unresolved';

    public function holdsTheSale(): bool
    {
        return $this === self::Pending || $this === self::Unresolved;
    }

    /** Ce que la tentative est devenue, pour un message au caissier. */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'en cours',
            self::Accepted => 'encaissée',
            self::Refused => 'refusée par le terminal',
            self::Failed => 'non aboutie',
            self::Unresolved => 'sans issue connue',
        };
    }
}
