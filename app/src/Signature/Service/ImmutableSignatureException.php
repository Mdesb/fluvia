<?php

declare(strict_types=1);

namespace App\Signature\Service;

/**
 * Levée lorsqu'une écriture (modification/suppression) est tentée sur une `ElectronicSignature`
 * scellée. Une preuve qu'on peut réécrire ou effacer ne prouve plus rien : la signature est
 * append-only, et une erreur de recueil se corrige en recueillant une NOUVELLE signature, pas en
 * retouchant l'ancienne.
 */
final class ImmutableSignatureException extends \RuntimeException
{
}
