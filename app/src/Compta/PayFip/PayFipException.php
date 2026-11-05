<?php

declare(strict_types=1);

namespace App\Compta\PayFip;

/**
 * A PayFiP call that did not produce a usable answer: a functional fault (`FonctionnelleErreur`, with
 * the code of annexes 8 and 9 of the DGFiP guide), a technical fault, or a transport error.
 *
 * `P1` (unknown operation id) and `P5` (result not known yet) are the two codes the return path must
 * tell apart from a refusal: neither says the buyer did not pay.
 */
final class PayFipException extends \RuntimeException
{
    public const UNKNOWN_OPERATION = 'P1';
    public const RESULT_NOT_KNOWN_YET = 'P5';

    public function __construct(string $message, private readonly ?string $faultCode = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function faultCode(): ?string
    {
        return $this->faultCode;
    }

    public function isResultNotKnownYet(): bool
    {
        return $this->faultCode === self::RESULT_NOT_KNOWN_YET;
    }
}
