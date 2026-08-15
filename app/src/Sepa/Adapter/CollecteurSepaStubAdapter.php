<?php

declare(strict_types=1);

namespace App\Sepa\Adapter;

use App\Sepa\Entity\RemiseSepa;
use App\Sepa\Port\CollecteurSepaInterface;

/**
 * Adaptateur SEPA par défaut — stub (repris de `App\Sport\Sepa\Adapter\CollecteurSepaStubAdapter`,
 * Risque n°2 du plan-sport). Génère une référence de transmission déterministe, ne transmet rien à une
 * vraie banque (EBICS/SFTP, §9 du plan, hors périmètre).
 */
final class CollecteurSepaStubAdapter implements CollecteurSepaInterface
{
    public function transmettre(RemiseSepa $remise): string
    {
        return 'TRANSMISSION-' . substr(hash('sha256', (string) $remise->getId() . $remise->getNbTxs()), 0, 16);
    }
}
