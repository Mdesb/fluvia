<?php

declare(strict_types=1);

namespace App\Platform\Nf525;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Scellement refusé parce que le dernier maillon de la chaîne ne se vérifie plus avec le trousseau
 * (#361). 409 : l'état de la chaîne interdit l'opération, la requête elle-même n'est pas fautive.
 */
final class SealingRefusedException extends ConflictHttpException
{
}
