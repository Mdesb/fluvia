<?php

declare(strict_types=1);

namespace App\Padel\Enum;

/**
 * Le sport pratique sur un terrain reservable (R13).
 *
 * Maxime : padel et tennis relevent de la meme verticale. Un terrain, un creneau, une grille
 * tarifaire par plage et par statut de joueur — la mecanique est identique ; seul le sport change,
 * et avec lui la surface et le nombre de joueurs.
 *
 * Nom anglais (D5) : enum AJOUTE, donc classe et cas en anglais.
 */
enum CourtSport: string
{
    case Padel = 'padel';
    case Tennis = 'tennis';
    case Squash = 'squash';
    case Badminton = 'badminton';
}
