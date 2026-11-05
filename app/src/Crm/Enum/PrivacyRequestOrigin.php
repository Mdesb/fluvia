<?php

declare(strict_types=1);

namespace App\Crm\Enum;

/**
 * D'où vient une demande RGPD — ce qui distingue une PERSONNE qui a demandé de l'exploitant qui a
 * épuré son fichier.
 *
 * ⚠ SANS CETTE DISTINCTION, UNE ÉPURATION DE MILLE FICHES NOIE LA FILE DES DEMANDES. L'écran
 * « Données personnelles » compte les demandes traitées et celles hors délai ; mille lignes
 * « anonymisation, traitée » posées par une épuration y passeraient pour mille personnes qui ont
 * écrit, et la seule qui a vraiment écrit s'y perdrait.
 *
 * Valeurs persistées, en anglais (D5).
 */
enum PrivacyRequestOrigin: string
{
    /** La personne concernée l'a demandé (courrier, courriel, guichet). */
    case Request = 'request';

    /** Épuration sélective lancée par un administrateur depuis l'écran, après aperçu. */
    case Purge = 'purge';

    /** Durée de conservation échue, appliquée par la tâche planifiée. */
    case Retention = 'retention';
}
