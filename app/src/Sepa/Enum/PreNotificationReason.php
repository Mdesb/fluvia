<?php

declare(strict_types=1);

namespace App\Sepa\Enum;

/**
 * Pourquoi ce préavis part.
 *
 * La distinction n'est pas décorative : un préavis d'échéancier annonce ce que le client a déjà
 * accepté, tandis qu'un préavis de bascule annonce un prélèvement qu'il n'attendait pas — sa carte
 * ayant été refusée. Le second demande un texte différent, et c'est la seule information qui permet
 * de le choisir.
 */
enum PreNotificationReason: string
{
    /** Échéance prévue au contrat. */
    case Schedule = 'schedule';

    /** Bascule vers le prélèvement après refus de la carte (PAY-2, D43). */
    case CardFallback = 'card_fallback';

    public function templateKey(): string
    {
        return match ($this) {
            self::Schedule => 'sepa.prenotification.schedule',
            self::CardFallback => 'sepa.prenotification.card_fallback',
        };
    }
}
