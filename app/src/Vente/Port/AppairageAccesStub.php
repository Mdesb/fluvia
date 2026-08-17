<?php

declare(strict_types=1);

namespace App\Vente\Port;

use App\Vente\Entity\BilletSupport;
use App\Vente\Enum\StatutAppairage;
use App\Vente\Service\GenerateurCodeSupport;

/**
 * Stub L2 du port Accès (L3). Appaire par défaut avec succès ; un échec peut être simulé via un
 * identifiant de support préfixé « ECHEC » (pour les tests CA-12). L'émission du code de support
 * (unique, signé HMAC — CA-12) relève désormais de `App\Vente\Service\ValiderVenteService::creerSupport()`
 * (`GenerateurCodeSupport`) : à ce stade, l'identifiant est donc déjà renseigné dans l'immense
 * majorité des cas — le recours au générateur ci-dessous n'est qu'un filet de sécurité défensif pour
 * un appelant direct du port (ex. ré-appairage) sur un support dépourvu d'identifiant.
 */
final class AppairageAccesStub implements AppairageAccesInterface
{
    public function __construct(
        private readonly GenerateurCodeSupport $generateurCode,
    ) {
    }

    public function appairer(BilletSupport $support): bool
    {
        if (str_starts_with((string) $support->getIdentifiantSupport(), 'ECHEC')) {
            $support->setStatutAppairage(StatutAppairage::Echec);

            return false;
        }

        if ($support->getIdentifiantSupport() === null) {
            $support->setIdentifiantSupport($this->generateurCode->genererPourType($support->getType()));
        }
        $support->setStatutAppairage(StatutAppairage::Actif);

        return true;
    }

    public function invalider(BilletSupport $support): void
    {
        $support->setStatutAppairage(StatutAppairage::Invalide);
    }
}
