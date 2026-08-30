<?php

declare(strict_types=1);

namespace App\Acces\Enum;

/**
 * Motif structuré d'une décision de passage (filtrage journal, CA-3/12). `FederationInactive` est une
 * extension locale (non littérale dans la spec) pour tracer distinctement un refus de fédération
 * inter-entités désactivée (US-L3-12, CA-13).
 */
enum CodeMotifRefus: string
{
    case HorsMarge = 'hors_marge';
    case AntiPassback = 'anti_passback';
    case CreditEpuise = 'credit_epuise';
    case SupportBloque = 'support_bloque';
    case SeuilFmi = 'seuil_fmi';
    case DroitInvalide = 'droit_invalide';
    case SensInterdit = 'sens_interdit';
    case NonNominatif = 'non_nominatif';
    case OuvertureManuelle = 'ouverture_manuelle';
    case FederationInactive = 'federation_inactive';
    /** Code de support signé (format `App\Vente\Service\GenerateurCodeSupport`) dont la signature HMAC
     *  ne correspond pas : code forgé/altéré, refusé avant toute résolution en base. */
    case SignatureInvalide = 'signature_invalide';
    /** `equipementId` hors de la portée (itboxRef) du `Terminal` authentifié (plan-acces-terminal.md §2.4). */
    case HorsPortee = 'hors_portee';

    /**
     * Cle d'idempotence fournie mais malformee (attendu : un UUID). L'entree est ECARTEE du rejeu et
     * jamais rejouee sous une cle engendree : une cle fournie puis remplacee ferait croire a
     * l'appelant qu'il est protege des doublons alors que chaque envoi en creerait de nouveaux.
     */
    case CleIdempotenceInvalide = 'cle_idempotence_invalide';
    /**
     * Le droit n'ouvre pas CET espace : le produit vendu ne donne pas accès à cette zone.
     *
     * ⚠ À ne pas confondre avec `HorsPortee`, qui parle du TERMINAL (un équipement hors de la
     * portée déclarée d'une ITBOX). Celui-ci parle du DROIT : le porteur est au bon endroit, son
     * billet ne couvre simplement pas cette zone. Les deux se ressemblent dans un journal et
     * appellent deux gestes opposés — vérifier une installation, ou vendre un complément.
     */
    case ZoneNonAutorisee = 'zone_non_autorisee';
    /** Réconciliation gracieuse hors-ligne (CA-8, plan-acces-terminal.md §4.2) : passage accepté malgré
     *  un dépassement de crédit détecté au rejeu — actif uniquement derrière le flag
     *  `EvenementPassageDto::autoriserCreditNegatifSiHorsLigne` (désactivé par défaut). */
    case CreditEpuiseHorsLigneLitige = 'credit_epuise_hors_ligne_litige';
    /**
     * Le site est fermé à cette heure-là, d'après son planning d'ouverture (module App\Ouverture,
     * 28/08) — et ce planning est déclaré « faisant loi » sur cet établissement.
     *
     * DISTINCT DE `HorsMarge`, ET CE N'EST PAS UNE NUANCE. `HorsMarge` dit que LE DROIT ne vaut pas
     * à cette heure — un abonnement heures creuses présenté à 19 h. Celui-ci dit que LE SITE est
     * fermé, quel que soit le droit. Confondre les deux ferait chercher un défaut de tarification
     * là où il n'y a qu'un rideau baissé, et inversement : la supervision compte les refus par
     * motif, et un motif emprunté est une statistique fausse.
     */
    case HorsHorairesOuverture = 'hors_horaires_ouverture';
    /**
     * Ce billet a DÉJÀ été contrôlé — par l'outil de scan, sur un site sans matériel (D86, 30/08).
     *
     * ⚠ DISTINCT DE `CreditEpuise`, ET LES CONFONDRE DONNERAIT LE MAUVAIS GESTE À L'AGENT. Un
     * crédit épuisé se recharge à la caisse : le porteur a consommé ce qu'il avait acheté. Un billet
     * déjà contrôlé n'appelle pas une recharge — il appelle une QUESTION, parce que deux causes
     * opposées produisent le même code : l'agent qui a scanné deux fois pour vérifier, et quelqu'un
     * d'autre qui se présente avec le même billet.
     *
     * C'est pourquoi la réponse porte l'HEURE du contrôle précédent à côté du motif, et pas
     * seulement dans la phrase : « il y a trente secondes » et « ce matin » appellent des gestes
     * inverses, et un écran qui devrait analyser une phrase française pour retrouver l'heure
     * casserait le jour où on corrige une faute d'orthographe.
     */
    case DejaConsomme = 'deja_consomme';
}
