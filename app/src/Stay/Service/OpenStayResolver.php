<?php

declare(strict_types=1);

namespace App\Stay\Service;

use App\Crm\Entity\Client;
use App\Organisation\Entity\Etablissement;

/**
 * **Le fil.** Étant donné une consommation — un établissement, un client, un moment — à quel séjour
 * doit-elle être portée ? (ACT-3, D16.)
 *
 * C'est la pièce que l'intégrateur a demandé d'inventer, et c'est la seule du module qui décide **où
 * va l'argent**. Le reste — écouter le bus, écrire la ligne — en découle mécaniquement.
 *
 * **Elle ne dépend d'aucun événement**, volontairement. `sale.completed` et `access.recorded` sont
 * catalogués mais émis par personne aujourd'hui, et ils appartiennent à d'autres périmètres. Le jour
 * où ils existeront, l'abonné du bus tiendra en dix lignes : il appellera ce résolveur, puis
 * {@see StayChargeRecorder}. La difficulté est ici, pas là-bas.
 *
 * **Trois issues, jamais une supposition.** Un client de passage n'est pas une anomalie ; deux séjours
 * ouverts pour le même client, si. Le détail du raisonnement est dans
 * {@see \App\Stay\Enum\StayResolutionReason}.
 */
final class OpenStayResolver
{
    public function __construct(private readonly OpenStayLookup $lookup)
    {
    }

    public function resolve(
        Etablissement $establishment,
        Client $customer,
        \DateTimeImmutable $at,
    ): StayResolution {
        $ouverts = $this->lookup->openStaysFor($establishment, $customer, $at);

        return match (\count($ouverts)) {
            0 => StayResolution::noOpenStay(),
            1 => StayResolution::matched($ouverts[0]),
            default => StayResolution::ambiguous(),
        };
    }
}
