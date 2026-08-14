<?php

declare(strict_types=1);

namespace App\Acces\Dto;

/**
 * DTO domaine (§2.1 du plan) : résultat d'une commande envoyée au matériel (ouverture, push de liste
 * de révocation). Agnostique du protocole (OSDP/API) ; confiné aux adaptateurs.
 */
final class ResultatCommande
{
    public function __construct(
        public readonly bool $succes,
        public readonly string $message = '',
    ) {
    }

    public static function ok(string $message = ''): self
    {
        return new self(true, $message);
    }

    public static function echec(string $message): self
    {
        return new self(false, $message);
    }
}
