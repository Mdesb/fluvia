<?php

declare(strict_types=1);

namespace App\Social\Enum;

/**
 * État d'un message (D14).
 *
 * Cet état est un **résumé** des publications, jamais une vérité indépendante : c'est la ligne par
 * réseau qui fait foi. Un résumé reste utile — on ne veut pas recalculer cinq lignes pour afficher une
 * liste — mais il se recalcule depuis elles et ne se pose jamais à la main.
 *
 * `PartiallyFailed` existe parce que c'est le cas normal, pas l'exception : cinq réseaux, trois qui
 * passent, un quota dépassé, un jeton expiré. Un modèle qui ne saurait dire que « publié » ou « échoué »
 * perdrait exactement l'information qui compte le jour de l'incident.
 */
enum SocialPostStatus: string
{
    /** Rédigé, aucune publication tentée. */
    case Draft = 'draft';

    /** Daté pour plus tard. */
    case Scheduled = 'scheduled';

    /** Au moins une publication en cours de traitement. */
    case Publishing = 'publishing';

    /** Toutes les publications ont réussi. */
    case Published = 'published';

    /** Au moins une réussie, au moins une échouée. */
    case PartiallyFailed = 'partially_failed';

    /** Aucune n'a réussi. */
    case Failed = 'failed';
}
