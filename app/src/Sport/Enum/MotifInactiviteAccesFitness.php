<?php

declare(strict_types=1);

namespace App\Sport\Enum;

/** Motif métier Sport de dévalidation du droit d'accès — non porté par L3 (§1.6 du plan). */
enum MotifInactiviteAccesFitness: string
{
    case Impaye = 'impaye';
    case Pause = 'pause';
    case Resiliation = 'resiliation';

    /**
     * ⚠ PAS « RESILIATION ». L'adherent n'a rien resilie : son engagement est arrive a son terme et
     * la formule prevoit de suspendre.
     *
     * Le motif est lu a l'accueil quand quelqu'un est refuse au tourniquet. Lui dire « resiliation »
     * enverrait l'agent chercher une demande que personne n'a faite, et l'adherent repartirait
     * convaincu d'avoir ete resilie sans le savoir. Un motif nomme le fait, jamais l'a-peu-pres le
     * plus proche.
     */
    case Terme = 'terme';
}
