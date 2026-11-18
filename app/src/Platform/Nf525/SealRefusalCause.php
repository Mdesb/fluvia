<?php

declare(strict_types=1);

namespace App\Platform\Nf525;

/**
 * Pourquoi une chaîne scellée est bloquée. Quatre causes de refus de sceller, plus `chain_broken` pour
 * une vérification qui trouve la chaîne rompue (alerte NF525).
 */
enum SealRefusalCause: string
{
    case InvalidSignature = 'invalid_signature';
    case UnknownKey = 'unknown_key';
    case KeyRegression = 'key_regression';
    case UnreadableOrder = 'unreadable_order';
    case ChainBroken = 'chain_broken';
}
