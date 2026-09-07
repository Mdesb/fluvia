<?php

declare(strict_types=1);

namespace App\Website\Exception;

use App\Fonctionnalite\Enum\EstablishmentActivity;

/**
 * On a tenté d'attribuer à un métier une activité qui n'est pas l'une des neuf de D15.
 *
 * ⚠ **LE MESSAGE NOMME LES NEUF, ET CE N'EST PAS DE LA POLITESSE.** « Valeur invalide » oblige celui
 * qui reçoit le refus à aller lire le code pour connaître la liste — c'est-à-dire à avoir accès au
 * dépôt. Les neuf activités sont un vocabulaire fermé et public : l'énumérer coûte une ligne et
 * transforme un refus opaque en une correction évidente.
 */
final class UnknownActivityException extends \RuntimeException
{
    public function __construct(string $recue)
    {
        parent::__construct(sprintf(
            'L’activité « %s » n’existe pas. Un établissement se compose de ces neuf activités et d’aucune '
            .'autre : %s. Ce vocabulaire est fermé — les modules d’un métier s’en déduisent, donc une '
            .'activité inventée ne pourrait allumer aucun module.',
            $recue,
            implode(', ', array_map(
                static fn (EstablishmentActivity $a): string => $a->value,
                EstablishmentActivity::cases(),
            )),
        ));
    }
}
