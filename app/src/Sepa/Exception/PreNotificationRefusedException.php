<?php

declare(strict_types=1);

namespace App\Sepa\Exception;

/**
 * On n'a pas pu prévenir le client, donc on ne prélèvera pas.
 *
 * Exception et non valeur de retour : un appelant qui oublierait de regarder un booléen prélèverait
 * sans préavis, ce qui est précisément l'accident que ce lot ferme.
 */
final class PreNotificationRefusedException extends \RuntimeException
{
}
