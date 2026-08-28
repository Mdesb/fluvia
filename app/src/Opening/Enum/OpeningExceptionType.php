<?php

declare(strict_types=1);

namespace App\Opening\Enum;

/**
 * Nature d'une `OpeningException` : ce que la date fait au planning hebdomadaire.
 *
 * Les deux sens sont nécessaires, et ce n'est pas une symétrie décorative. Une fermeture
 * exceptionnelle (jour férié, travaux, congés) se saisit une fois par an ; une ouverture
 * exceptionnelle (nocturne, journée portes ouvertes, compétition un dimanche) aussi — et sans elle,
 * l'exploitant n'aurait d'autre choix que de modifier son planning hebdomadaire pour un seul jour,
 * puis de penser à le remettre. Personne ne pense à le remettre.
 */
enum OpeningExceptionType: string
{
    case Closure = 'closure';
    case SpecialOpening = 'special_opening';
}
