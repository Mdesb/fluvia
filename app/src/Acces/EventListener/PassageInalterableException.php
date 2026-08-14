<?php

declare(strict_types=1);

namespace App\Acces\EventListener;

/** Levée par `PassageInalterableListener` (CA-14) : le journal des passages est append-only. */
final class PassageInalterableException extends \RuntimeException
{
}
