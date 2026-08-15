<?php

declare(strict_types=1);

namespace App\Fonctionnalite\Enum;

/**
 * Registre des capacités connues du socle (RG-SOCLE, spec « Profil de fonctionnalités par
 * établissement »). Une capacité est une fonctionnalité générique, activable indépendamment par
 * établissement (`FonctionnaliteEtablissement`). Liste extensible : ajouter un cas ici + son
 * descripteur dans `App\Fonctionnalite\Service\CatalogueCapacites` suffit (aucune migration requise,
 * `FonctionnaliteEtablissement.capaciteCode` est une colonne texte libre).
 */
enum CapaciteCode: string
{
    case ControleAcces = 'controle_acces';
    case Reservation = 'reservation';
    case NoShow = 'no_show';
    case Recouvrement = 'recouvrement';
    case Sepa = 'sepa';
    case PorteMonnaie = 'porte_monnaie';
    case Casiers = 'casiers';
    case LocationMateriel = 'location_materiel';
    case Poss = 'poss';
    case AccesNocturne = 'acces_nocturne';
    case Encadrants = 'encadrants';
    case BoutiqueEnLigne = 'boutique_en_ligne';
}
