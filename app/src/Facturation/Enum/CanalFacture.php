<?php

declare(strict_types=1);

namespace App\Facturation\Enum;

/**
 * Canal d'émission (RG-FACT-07). Le canal `pdp` (Plateforme de Dématérialisation Partenaire) de la
 * facture électronique B2B est **signalé mais non implémenté** (`spec-facturation.md` §2/§4.7) :
 * il s'ajoutera ici sans changement de modèle.
 */
enum CanalFacture: string
{
    case Pdf = 'pdf';
    case ChorusPro = 'chorus_pro';
}
