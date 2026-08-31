<?php

declare(strict_types=1);

namespace App\Sepa\Exception;

/**
 * La bascule carte vers prelevement est impossible, et on ne la fera pas.
 *
 * Exception et non valeur de retour : un appelant qui oublierait de regarder un booleen laisserait la
 * somme impayee sans que personne ne le sache, ce qui est la panne la plus couteuse de ce lot.
 */
final class CardFallbackRefusedException extends \RuntimeException
{
}
