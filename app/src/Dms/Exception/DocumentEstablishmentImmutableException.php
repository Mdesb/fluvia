<?php

declare(strict_types=1);

namespace App\Dms\Exception;

/**
 * Levée par `App\Dms\Doctrine\DocumentIntegrityListener` lorsqu'une écriture ORM modifie
 * `Document.establishment` après création (RG-DMS-04) — défense en profondeur en complément du groupe
 * de sérialisation `document:write`, qui n'expose déjà pas ce champ en écriture API.
 */
final class DocumentEstablishmentImmutableException extends \RuntimeException
{
}
