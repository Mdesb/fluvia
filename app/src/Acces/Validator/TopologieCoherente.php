<?php

declare(strict_types=1);

namespace App\Acces\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Cohérence de la topologie Espace › Contrôleur › Équipement (US-L3-01, CA-1) : équipement orphelin,
 * sens manquant ou contrôleur sans ITBOX sont refusés à l'enregistrement (422).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class TopologieCoherente extends Constraint
{
    public string $messageOrphelin = 'Topologie incohérente : équipement sans contrôleur (CA-1).';
    public string $messageSens = 'Topologie incohérente : sens manquant sur l\'équipement (CA-1).';
    public string $messageItbox = 'Topologie incohérente : contrôleur sans référence ITBOX (CA-1).';
    public string $messageSeuil = 'Topologie incohérente : espace sans seuil de jauge/FMI (CA-1, RG-ACC-04).';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
