<?php

declare(strict_types=1);

namespace App\Membership\Enum;

/**
 * Statut d'un abonnement d'adhérent. Miroir de `App\Sport\Enum\StatutAbonnementFitness`, valeurs en
 * anglais (D5).
 *
 * La correspondance à appliquer par la migration de données du **lot 1**, écrite ici pour qu'elle ne
 * se perde pas entre deux lots :
 *
 *     actif    -> active
 *     pause    -> paused
 *     impaye   -> unpaid
 *     resilie  -> terminated
 *     echu     -> expired
 *
 * ⚠ `Expired` MÉRITE D'ÊTRE LU, PAS SEULEMENT RECOPIÉ. Ce cas manquait côté Sport et son absence
 * coûtait cher : un abonnement arrivé au terme de son engagement restait « actif » pendant que ses
 * échéances s'arrêtaient. Le prélèvement cessait, l'accès restait valide, et l'adhérent continuait
 * d'entrer gratuitement — sans qu'aucun écran puisse seulement le DISTINGUER d'un abonnement en
 * cours.
 *
 * ⚠ ET C'EST LUI QUI JUSTIFIE D'AVOIR TRADUIT LES VALEURS. Le cas `Echu` porte, côté Sport, une
 * valeur française assumée à contre-courant de D5 : ses quatre voisins sont des codes déjà
 * persistés en français, et glisser un `expired` au milieu rendrait toute lecture de la colonne
 * ambiguë. Cet argument vaut pour une table existante. Ici la table est NEUVE et ne contient rien :
 * c'est la seule occasion de ne pas hériter du mélange, et elle ne se représentera pas.
 *
 * ⚠ AUCUN COMPORTEMENT ICI. Ce que ces statuts déclenchent — coupure d'accès, révocation de mandat,
 * annulation d'échéances — appartient aux handlers, qui se recâblent au lot 1.
 */
enum MembershipStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Unpaid = 'unpaid';
    case Terminated = 'terminated';
    case Expired = 'expired';
}
