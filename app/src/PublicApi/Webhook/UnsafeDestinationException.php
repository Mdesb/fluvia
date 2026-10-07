<?php

declare(strict_types=1);

namespace App\PublicApi\Webhook;

/** La destination d'un webhook partenaire a été refusée par l'anti-SSRF : on ne l'appelle pas. */
final class UnsafeDestinationException extends \RuntimeException
{
}
