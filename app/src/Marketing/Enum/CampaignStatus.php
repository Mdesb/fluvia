<?php

declare(strict_types=1);

namespace App\Marketing\Enum;

/**
 * L'état d'une campagne — et surtout, ce qu'il autorise.
 *
 * **Quatre états, et un seul chemin possible entre eux.** Un brouillon se prépare, une planifiée
 * attend son heure, une envoyée ne se rejoue pas, une arrêtée garde ce qui est déjà parti.
 *
 * **`Envoyee` est définitif.** On ne « renvoie » pas une campagne : on en crée une autre. Rejouer
 * signifierait recontacter des gens qui ont déjà reçu le message, et le plafond de sollicitation
 * existe précisément pour empêcher ça. Un bouton « renvoyer » serait la façon la plus simple de
 * perdre un canal pour toujours.
 */
enum CampaignStatus: string
{
    case Brouillon = 'brouillon';
    case Planifiee = 'planifiee';
    case Envoyee = 'envoyee';

    /**
     * Arrêtée en cours d'envoi.
     *
     * Les déjà-contactés restent au journal et à l'attribution : on ne réécrit pas l'histoire, et
     * les ventes qu'ils feront viennent bien de ce message-là.
     */
    case Arretee = 'arretee';

    public function label(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::Planifiee => 'Planifiée',
            self::Envoyee => 'Envoyée',
            self::Arretee => 'Arrêtée',
        };
    }

    /** Une campagne déjà partie ne se modifie plus : son message est sorti. */
    public function estModifiable(): bool
    {
        return $this === self::Brouillon || $this === self::Planifiee;
    }
}
