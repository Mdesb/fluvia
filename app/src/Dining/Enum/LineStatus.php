<?php

declare(strict_types=1);

namespace App\Dining\Enum;

/**
 * Le cycle de vie d'une ligne d'addition (ACT-4, D16).
 *
 * **La frontière qui compte n'est pas entre « pas encore payé » et « payé » : elle est entre
 * `Draft` et `Fired`.** Tant qu'une ligne est au brouillon, elle n'existe qu'à l'écran du serveur et
 * s'efface sans conséquence. Une fois envoyée, la cuisine a commencé — la marchandise est engagée,
 * qu'on la serve ou non. Tout le module tient à cette distinction.
 */
enum LineStatus: string
{
    /** Saisie au comptoir, pas encore partie en cuisine. Effaçable sans trace. */
    case Draft = 'draft';

    /** Envoyée en cuisine. **Irréversible** : à partir d'ici, la matière est engagée. */
    case Fired = 'fired';

    /** Servie au client. */
    case Served = 'served';

    /**
     * Annulée après envoi.
     *
     * Distinct d'un simple retrait, et c'est le cœur de la règle : une ligne annulée après envoi
     * **reste une consommation**. Elle sort de l'addition du client et entre en perte. La faire
     * disparaître ferait mentir à la fois la note et le stock — et c'est le stock qui, lui, ne se
     * rattrape pas.
     */
    case Voided = 'voided';
}
