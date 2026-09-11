<?php

declare(strict_types=1);

namespace App\Membership\Enum;

/**
 * Statut de l'abonnement d'un adhérent (RG-SPORT-04/05/06/07), pilote la projection d'accès.
 *
 * ⚠ VALEURS EN ANGLAIS DEPUIS LE 11/09, ET C'EST UN RETOUR À D5, PAS UNE NOUVEAUTÉ.
 *
 * Le lot 0 avait posé cette énumération en anglais (décision D‑4). Le lot 1 a fait pointer l'entité
 * sur `sport_abonnement_fitness` — arbitrage « déplace sans renommer », motivé par les sept clés
 * étrangères de cette table — et l'énumération a suivi les valeurs françaises qui y dormaient.
 * Cohérent sur le moment, mais ça laissait D‑4 écrite et contredite.
 *
 * Arbitrage du 11/09 : l'anglais, parce que c'est la convention du dépôt (D5) pour tout ce qui est
 * technique. Les valeurs ont été migrées (`Version20260911...`) ; **les sept clés étrangères portent
 * sur `id`, aucune ne porte sur `statut`** — c'est pourquoi ce renommage ne coûte pas ce que coûtait
 * le déplacement de table qu'on avait écarté.
 *
 * La correspondance, écrite ici parce qu'on la relira en ouvrant une sauvegarde antérieure au 11/09
 * ou un journal de cette période :
 *
 *     actif -> active · pause -> paused · impaye -> unpaid · resilie -> terminated · echu -> expired
 */
enum MembershipStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Unpaid = 'unpaid';
    case Terminated = 'terminated';

    /**
     * ⚠ ARRIVÉ AU TERME DE SON ENGAGEMENT, ET RIEN NE L'A RECONDUIT.
     *
     * Ce cas manquait, et son absence coûtait cher : un abonnement au terme restait « actif »
     * pendant que ses échéances s'arrêtaient. Le prélèvement cessait, l'accès restait valide, et
     * l'adhérent continuait d'entrer gratuitement -- sans qu'aucun écran ne puisse seulement
     * DISTINGUER cet abonnement d'un abonnement en cours.
     *
     * ⚠ « EXPIRED » SE DIT « AU TERME » À L'ÉCRAN, PAS « PÉRIMÉ ». L'adhérent n'a rien laissé
     * périmer : son engagement est arrivé à son terme. Quatre autres énumérations du dépôt émettent
     * `expired` — un devis, deux cas SmartFlow, une rétention GED — et pour celles-là « Périmé » est
     * le mot juste. Le mot de l'abonnement se pose donc dans `frontend/src/api/abonnement.js`,
     * jamais dans la carte globale de `vocabulaire.js`.
     */
    case Expired = 'expired';
}
