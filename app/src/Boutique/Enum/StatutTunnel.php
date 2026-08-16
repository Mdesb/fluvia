<?php

declare(strict_types=1);

namespace App\Boutique\Enum;

/** Étape du tunnel d'achat en ligne (§6 spec-boutique.md). */
enum StatutTunnel: string
{
    case Panier = 'panier';
    case Identifie = 'identifie';
    case Paye = 'paye';
    case Confirme = 'confirme';
}
