<?php

declare(strict_types=1);

namespace App\Membership\Enum;

/**
 * Cadence de prélèvement d'un abonnement d'adhérent.
 *
 * ⚠ LES VALEURS SONT EN ANGLAIS ALORS QUE CELLES DE `App\Sport\Enum\PeriodiciteAbonnementFitness`
 * SONT EN FRANÇAIS, ET CE N'EST PAS UN OUBLI.
 *
 * D5 impose l'anglais aux valeurs d'énumération du code neuf. Le garde-fou n°2 ne contrôle que les
 * NOMS de cas, pas leurs valeurs : rien ne m'aurait empêché de recopier `mensuel`/`annuel` pour que
 * la migration de données du lot 1 soit une copie brute. Ce raccourci économiserait cinq lignes de
 * SQL écrites une fois, contre une énumération à moitié traduite qu'on lira pendant des années.
 *
 * La correspondance à appliquer au **lot 1**, écrite ici pour qu'elle ne se perde pas :
 *
 *     mensuel      -> monthly
 *     hebdomadaire -> weekly
 *     annuel       -> yearly
 *
 * ⚠ `Hebdomadaire` EST REPRIS BIEN QUE PERSONNE NE L'ÉCRIVE. L'énumération Sport le documente :
 * aucun abonnement hebdomadaire n'existe (zéro en préproduction) et rien ne le produit, mais le cas
 * reste « pour ne pas invalider une ligne en base ». Le retirer ici ferait échouer la migration du
 * lot 1 sur la première ligne hebdomadaire qu'on n'aurait pas vue venir.
 *
 * ⚠ AUCUN COMPORTEMENT ICI, ET C'EST DÉLIBÉRÉ. L'énumération Sport porte `increment()`,
 * `porteUnJourDuMois()` et `depuisFormule()` : c'est l'échéancier qui les consomme, et l'échéancier
 * se recâble au lot 1. Les recopier maintenant serait poser du code que rien n'appelle, dans un lot
 * qui n'a pas encore d'appelant.
 */
enum MembershipPeriodicity: string
{
    case Monthly = 'monthly';
    case Weekly = 'weekly';
    case Yearly = 'yearly';
}
