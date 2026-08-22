<?php

declare(strict_types=1);

namespace App\Dms\Exception;

/**
 * Levée par `App\Dms\Doctrine\DocumentIntegrityListener` lorsqu'une écriture (`preUpdate`/`preRemove`)
 * est tentée sur une `DocumentVersion` déjà persistée (RG-DMS-17, CA-7) — append-only, même famille que
 * `App\Vente\Nf525\OperationInalterableException`. Couvre l'API **et** l'accès ORM direct.
 */
final class DocumentVersionImmutableException extends \RuntimeException
{
}
