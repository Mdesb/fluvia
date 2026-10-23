<?php

declare(strict_types=1);

namespace App\Vente\Tpe;

use App\Caisse\Entity\PointDeVente;
use Symfony\Component\Uid\Uuid;

/**
 * Ce qu'un connecteur reçoit d'une tentative de règlement : de quoi la nommer au terminal (son
 * identifiant revient avec l'issue, {@see TerminalOutcomeSink::record()}) et le montant à débiter.
 */
final class TerminalAttempt
{
    public function __construct(
        /** L'identifiant de la tentative, en hexadécimal (32 caractères) : celui que l'issue rapporte. */
        public readonly string $id,
        public readonly Uuid $key,
        public readonly string $method,
        /** Le montant à débiter, décimal (« 45.00 »). */
        public readonly string $amount,
        public readonly PointDeVente $pointOfSale,
    ) {
    }

    public function uuid(): string
    {
        return Uuid::fromBinary((string) hex2bin($this->id))->toRfc4122();
    }
}
