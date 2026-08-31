<?php

declare(strict_types=1);

namespace App\Project\Enum;

/**
 * L'état d'un projet — et ce qui n'en est **pas** un.
 *
 * **« En retard » ne figure pas dans cette liste, et c'est délibéré.** Un retard se déduit d'une date
 * d'échéance et de la date du jour : en faire un état obligerait à le maintenir, donc à passer quelque
 * part toutes les nuits — et un projet serait « à l'heure » jusqu'au prochain passage, puis en retard
 * d'un coup, sans que rien ne se soit produit.
 *
 * > **Un état qui se calcule ne se stocke pas : stocké, il est faux entre deux calculs.**
 *
 * Même raisonnement que l'avancement, qui se compte depuis les tâches plutôt que d'être recopié.
 */
enum ProjectStatus: string
{
    /** Décidé, pas commencé. */
    case Planned = 'planned';

    case Active = 'active';

    /** Suspendu — distinct d'abandonné : il reprendra. */
    case OnHold = 'on_hold';

    case Done = 'done';

    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'À venir',
            self::Active => 'En cours',
            self::OnHold => 'En pause',
            self::Done => 'Terminé',
            self::Cancelled => 'Abandonné',
        };
    }

    /** Vrai quand le projet ne demande plus rien : il sort des listes de travail. */
    public function closed(): bool
    {
        return $this === self::Done || $this === self::Cancelled;
    }
}
