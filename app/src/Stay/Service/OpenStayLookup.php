<?php

declare(strict_types=1);

namespace App\Stay\Service;

use App\Crm\Entity\Client;
use App\Organisation\Entity\Etablissement;
use App\Stay\Entity\Stay;

/**
 * Recherche des séjours ouverts d'un client dans un établissement (ACT-3).
 *
 * Isolée derrière une interface pour la même raison que {@see StayChargeLookup} : la règle de
 * rattachement décide **où va l'argent**, elle doit être couverte en unitaire. Et l'implémentation
 * Doctrine, elle, est couverte en intégration — l'inverse ne suffit pas, comme l'a montré la liaison
 * de paramètres UUID muette du 24/08.
 */
interface OpenStayLookup
{
    /**
     * Les séjours **ouverts** de ce client dans cet établissement, commencés au plus tard à cette date.
     *
     * @return list<Stay>
     */
    public function openStaysFor(Etablissement $establishment, Client $customer, \DateTimeImmutable $at): array;
}
