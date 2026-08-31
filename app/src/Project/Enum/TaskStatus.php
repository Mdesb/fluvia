<?php

declare(strict_types=1);

namespace App\Project\Enum;

/**
 * L'état d'une tâche — trois valeurs, et pas une de plus.
 *
 * **La tentation est d'en ajouter** : « en attente », « bloquée », « à valider ». Chacune se défend
 * isolément, et ensemble elles produisent le tableau que personne ne tient à jour — parce qu'il faut
 * réfléchir avant de déplacer une carte.
 *
 * Ce qu'« en attente » veut dire s'écrit dans la tâche elle-même. Ce qui bloque se dit à quelqu'un.
 *
 * > **Un état de plus, c'est une hésitation de plus à chaque déplacement — et une hésitation, ça se
 * > remet à plus tard.**
 */
enum TaskStatus: string
{
    case Todo = 'todo';
    case Doing = 'doing';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Todo => 'À faire',
            self::Doing => 'En cours',
            self::Done => 'Faite',
        };
    }
}
