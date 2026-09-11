<?php

declare(strict_types=1);

namespace App\Membership\Enum;

/** Statut de l'abonnement fitness (RG-SPORT-04/05/06/07), pilote la projection d'accès (§0 du plan). */
enum MembershipStatus: string
{
    case Actif = 'actif';
    case Pause = 'pause';
    case Impaye = 'impaye';
    case Resilie = 'resilie';

    /**
     * ⚠ ARRIVE AU TERME DE SON ENGAGEMENT, ET RIEN NE L'A RECONDUIT.
     *
     * Ce cas manquait, et son absence coutait cher : un abonnement au terme restait « actif »
     * pendant que ses echeances s'arretaient. Le prelevement cessait, l'acces restait valide, et
     * l'adherent continuait d'entrer gratuitement -- sans qu'aucun ecran ne puisse seulement
     * DISTINGUER cet abonnement d'un abonnement en cours.
     *
     * ⚠ VALEUR EN FRANCAIS, A CONTRE-COURANT DE D5, ET DELIBEREMENT. Les quatre cas existants sont
     * des codes PERSISTES en francais (`actif`, `pause`, `impaye`, `resilie`) : introduire un
     * `expired` au milieu rendrait toute lecture de la colonne ambigue. D5 porte sur les fichiers
     * AJOUTES ; celui-ci ne l'est pas, et les fichiers neufs de ce lot sont bien en anglais.
     */
    case Echu = 'echu';
}
