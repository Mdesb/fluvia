<?php

declare(strict_types=1);

namespace App\Caisse\Enum;

/**
 * Nature d'un mouvement d'espèces sur une session (cahier M2-§4/§8, US-L2-10).
 */
enum TypeMouvement: string
{
    case Entree = 'entree';
    case Sortie = 'sortie';
    case Apport = 'apport';
    case Retrait = 'retrait';
    case Versement = 'versement';
}
