<?php

declare(strict_types=1);

namespace App\Platform\Nf525;

/**
 * Ce qui a ouvert l'alerte, et donc la règle qui la ferme : un scellement réussi ferme une alerte
 * `refusal` ; seule une vérification vivante intacte ferme une alerte `verification`.
 */
enum SealAlertOrigin: string
{
    case Refusal = 'refusal';
    case Verification = 'verification';
}
