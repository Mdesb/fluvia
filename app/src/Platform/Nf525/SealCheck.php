<?php

declare(strict_types=1);

namespace App\Platform\Nf525;

/** Verdict d'une signature contre le trousseau (#361). « Clé inconnue » n'est pas « signature invalide ». */
enum SealCheck: string
{
    case Valid = 'valid';
    case InvalidSignature = 'invalid_signature';
    case UnknownKey = 'unknown_key';
}
