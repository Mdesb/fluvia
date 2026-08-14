<?php

declare(strict_types=1);

namespace App\Vente\Port;

use App\Vente\Entity\BilletSupport;
use App\Vente\Enum\StatutAppairage;
use Symfony\Component\Uid\Uuid;

/**
 * Stub L2 du port Accès (L3). Appaire par défaut avec succès en générant un identifiant de support ;
 * un échec peut être simulé via un identifiant de support préfixé « ECHEC » (pour les tests CA-12).
 */
final class AppairageAccesStub implements AppairageAccesInterface
{
    public function appairer(BilletSupport $support): bool
    {
        if (str_starts_with((string) $support->getIdentifiantSupport(), 'ECHEC')) {
            $support->setStatutAppairage(StatutAppairage::Echec);

            return false;
        }

        if ($support->getIdentifiantSupport() === null) {
            $support->setIdentifiantSupport('SUP-' . strtoupper(substr(Uuid::v4()->toRfc4122(), 0, 12)));
        }
        $support->setStatutAppairage(StatutAppairage::Actif);

        return true;
    }

    public function invalider(BilletSupport $support): void
    {
        $support->setStatutAppairage(StatutAppairage::Invalide);
    }
}
