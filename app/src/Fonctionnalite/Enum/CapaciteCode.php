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

    // ── Ajoutees le 01/09/2026 (decision Maxime) ────────────────────────────────────────────
    //
    // Les trois repondent au meme constat : un ecran ne peut pas cacher ce que le socle ne sait
    // pas nommer. La fiche produit melait acces et comptabilite dans un onglet unique parce
    // qu'aucun code ne permettait de savoir si l'exploitant tient l'un, l'autre, ou les deux.
    //
    // ⚠ NOMMAGE EN FRANCAIS, A CONTRE-COURANT DE D5. Les douze cas existants le sont
    // (`controle_acces`, `porte_monnaie`, `boutique_en_ligne`) et ces valeurs sont des CODES
    // PERSISTES : melanger les langues dans un meme jeu rendrait toute lecture ambigue, et
    // renommer les douze anciens est un autre chantier. Le garde-fou de nommage ne controle que
    // les fichiers AJOUTES, donc il n'a rien a dire ici — c'est un choix, pas un contournement.
    case Comptabilite = 'comptabilite';
    case Stock = 'stock';
    case Agenda = 'agenda';
}
