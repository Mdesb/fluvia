<?php

declare(strict_types=1);

namespace App\Crm\Enum;

/**
 * Ce qui s'est passé avec un client — appel, courriel, rendez-vous, note.
 *
 * **Quatre valeurs, et surtout pas de « tâche ».** Une tâche commerciale n'est pas un objet : c'est le
 * *prochain geste* d'un échange qui a déjà eu lieu, et il vit sur l'activité elle-même
 * (`nextActionAt`). En faire une entité créerait une seconde liste de choses à faire à côté du module
 * `Project` — et deux listes de travail dans un même produit, personne ne regarde les deux.
 *
 * > **Ce n'est pas parce qu'on peut ranger une chose ailleurs qu'il faut lui construire un tiroir.**
 */
enum ActivityType: string
{
    case Call = 'call';
    case Email = 'email';
    case Meeting = 'meeting';

    /** Ce qu'on note sans que ce soit un échange : une information apprise, un contexte. */
    case Note = 'note';

    public function label(): string
    {
        return match ($this) {
            self::Call => 'Appel',
            self::Email => 'Courriel',
            self::Meeting => 'Rendez-vous',
            self::Note => 'Note',
        };
    }
}
