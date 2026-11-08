<?php

declare(strict_types=1);

namespace App\Caisse\Service;

/**
 * Issue d'une saisie de code agent (#346), rendue par `AgentPinPolicy::check()`.
 */
enum AgentPinOutcome: string
{
    /** Le code saisi est celui de l'agent ; le compteur d'échecs repart à zéro. */
    case Accepted = 'accepted';
    /** Le code saisi n'est pas le bon ; un échec de plus est compté. */
    case Rejected = 'rejected';
    /** Trop d'échecs consécutifs : refusé même juste, jusqu'à redéfinition ou effacement. */
    case Locked = 'locked';
    /** L'agent n'a jamais défini de code : rien à comparer, donc rien n'est accepté. */
    case Undefined = 'undefined';
}
