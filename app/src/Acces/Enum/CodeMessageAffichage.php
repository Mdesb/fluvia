<?php

declare(strict_types=1);

namespace App\Acces\Enum;

/**
 * Catalogue fermé des messages d'affichage borne (US-TERM-02, spec-acces-terminal.md §4.5). Un enum PHP
 * garantit un catalogue fermé par construction (contrainte du langage, zéro migration) : ajouter un
 * futur motif = ajouter un `case` + une ligne `match` dans `CatalogueMessageAffichage`.
 */
enum CodeMessageAffichage: string
{
    case BonneSeance = 'BONNE_SEANCE';
    case PassageCompte = 'PASSAGE_COMPTE';
    case HorsMarge = 'HORS_MARGE';
    case DejaPasse = 'DEJA_PASSE';
    case CarteEpuisee = 'CARTE_EPUISEE';
    case SupportBloque = 'SUPPORT_BLOQUE';
    case JaugeAtteinte = 'JAUGE_ATTEINTE';
    case DroitInvalide = 'DROIT_INVALIDE';
    case SensInterdit = 'SENS_INTERDIT';
    case FederationInactive = 'FEDERATION_INACTIVE';
    case CodeInvalide = 'CODE_INVALIDE';

    /** Libellé par défaut (⚠ proposition, spec §4.5 — personnalisation par établissement non actée). */
    public function libelle(): string
    {
        return match ($this) {
            self::BonneSeance => 'Bonne séance !',
            self::PassageCompte => 'Passage enregistré',
            self::HorsMarge => 'Hors horaires autorisés',
            self::DejaPasse => 'Déjà passé, veuillez patienter',
            self::CarteEpuisee => 'Carte épuisée — rechargez à la caisse',
            self::SupportBloque => 'Support bloqué, présentez-vous à l\'accueil',
            self::JaugeAtteinte => 'Capacité maximale atteinte',
            self::DroitInvalide => 'Accès non valide',
            self::SensInterdit => 'Sens non autorisé ici',
            self::FederationInactive => 'Accès non autorisé sur ce site',
            self::CodeInvalide => 'Code illisible, veuillez réessayer',
        };
    }
}
