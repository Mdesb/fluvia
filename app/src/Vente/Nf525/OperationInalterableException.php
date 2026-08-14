<?php

declare(strict_types=1);

namespace App\Vente\Nf525;

/**
 * Levée lorsqu'une écriture (modification/suppression) est tentée sur une opération scellée ou une
 * vente validée (NF525, RG-M2-07 / CA-15). Toute correction doit passer par contre-passation.
 */
final class OperationInalterableException extends \RuntimeException
{
}
