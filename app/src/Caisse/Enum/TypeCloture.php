<?php

declare(strict_types=1);

namespace App\Caisse\Enum;

/**
 * Type de clôture (US-L2-11) : Z (par session), puis clôtures périodiques mensuelle/annuelle
 * (#342 — `App\Vente\Nf525\Entity\PeriodClosure`, par point de vente, déclenchées par un utilisateur
 * habilité ; l'arrêté de journée NF525 est `DailyClosure`, D57).
 */
enum TypeCloture: string
{
    case Z = 'Z';
    case Mensuelle = 'mensuelle';
    case Annuelle = 'annuelle';
}
