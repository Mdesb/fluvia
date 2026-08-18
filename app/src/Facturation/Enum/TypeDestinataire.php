<?php

declare(strict_types=1);

namespace App\Facturation\Enum;

/**
 * Nature du destinataire de facturation (RG-FACT-08) : conditionne les champs obligatoires de
 * l'instantané figé — SIRET requis pour une personne morale, jamais pour un particulier
 * (`spec-facturation.md` §7, cas limite « client sans SIRET »).
 */
enum TypeDestinataire: string
{
    case Particulier = 'particulier';
    case PersonneMorale = 'personne_morale';
}
