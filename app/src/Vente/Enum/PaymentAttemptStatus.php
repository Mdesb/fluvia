<?php

declare(strict_types=1);

namespace App\Vente\Enum;

/**
 * Où en est une tentative de règlement (G-3, G-6 du ticket opposable ; spec TPE lot 1).
 *
 * Trois statuts tiennent la vente — aucun autre règlement n'y passe : `Pending` (l'effet est en
 * cours), `Sent` (la demande attend le terminal) et `Unresolved` (le terminal a pu débiter, personne
 * ne sait). Les autres sont des issues et la libèrent ; deux sont DÉCLARÉES par le caissier, qui a lu
 * le terminal. `Cancelled` cède devant une acceptation tardive : le client a été débité.
 */
enum PaymentAttemptStatus: string
{
    /** Écrite et validée en base AVANT l'effet : le terminal ou le porte-monnaie peut être en train d'agir. */
    case Pending = 'pending';

    /**
     * Envoyée au terminal, qui a rendu la main (port asynchrone, spec TPE lot 1) : l'issue arrivera par
     * `TerminalOutcomeSink`. Au-delà du délai du connecteur, elle est jugée `Unresolved`.
     */
    case Sent = 'sent';

    /** Le règlement est écrit, dans la même transaction que ce statut. */
    case Accepted = 'accepted';

    /** Le terminal a refusé, ou le porteur a annulé : aucun argent n'a bougé. Rejouée, la clé rend ce refus. */
    case Refused = 'refused';

    /**
     * Annulée pendant l'attente (le caissier, ou le terminal) : rien n'a été débité, la vente est
     * libérée. Une acceptation qui arrive ensuite l'emporte et écrit son règlement (CA-3).
     */
    case Cancelled = 'cancelled';

    /** Rien n'a bougé (demande refusée, ou débit annulé avec l'écriture) : la même demande peut être rejouée. */
    case Failed = 'failed';

    /** Timeout, réponse perdue, processus interrompu : rien ne repart au terminal avant une déclaration (Q-A1). */
    case Unresolved = 'unresolved';

    /** Sans issue, puis déclarée « accepté » : le règlement est écrit avec la référence du ticket CB, sans terminal. */
    case DeclaredAccepted = 'declared_accepted';

    /** Sans issue, puis déclarée « non passé » : rien d'écrit, la vente est libérée pour un nouvel envoi. */
    case DeclaredNotProcessed = 'declared_not_processed';

    public function holdsTheSale(): bool
    {
        return $this === self::Pending || $this === self::Sent || $this === self::Unresolved;
    }

    /** Ce que la tentative est devenue, pour un message au caissier. */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'en cours',
            self::Sent => 'en attente du terminal',
            self::Accepted => 'encaissée',
            self::Refused => 'refusée par le terminal',
            self::Cancelled => 'annulée',
            self::Failed => 'non aboutie',
            self::Unresolved => 'sans issue connue',
            self::DeclaredAccepted => 'déclarée acceptée',
            self::DeclaredNotProcessed => 'déclarée non passée',
        };
    }
}
