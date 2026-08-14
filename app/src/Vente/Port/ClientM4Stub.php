<?php

declare(strict_types=1);

namespace App\Vente\Port;

use Symfony\Component\Uid\Uuid;

/**
 * Stub L2 du port CRM (M4). La recherche renvoie un identifiant logique déterministe dérivé du
 * critère (UUIDv5) pour être stable et testable ; la création rapide génère un identifiant dédié.
 */
final class ClientM4Stub implements ClientM4Interface
{
    private const NAMESPACE = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

    public function rechercher(string $critere): ?Uuid
    {
        $critere = trim($critere);
        if ($critere === '') {
            return null;
        }

        return Uuid::v5(Uuid::fromString(self::NAMESPACE), 'client:' . mb_strtolower($critere));
    }

    public function creerRapide(array $donnees): Uuid
    {
        $graine = json_encode($donnees) ?: uniqid('client', true);

        return Uuid::v5(Uuid::fromString(self::NAMESPACE), 'client-cree:' . $graine);
    }
}
