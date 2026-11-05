<?php

declare(strict_types=1);

namespace App\Kiosk;

use App\Platform\Module\ModuleManifest;

/**
 * Manifeste du module `App\Kiosk` — la BORNE AUTOMATIQUE DE VENTE en libre-service (#341).
 *
 * L'usager y achète une entrée, ou recharge sa carte, paie par carte bancaire sur le terminal piloté
 * du point de vente de la borne, puis reçoit son ticket (ou son badge encodé). Une borne EST un point
 * de vente : sa chaîne NF525, son terminal, son imprimante et ses moyens de paiement sont ceux de son
 * `PointDeVente`. Le module n'ajoute que ce qu'un guichet n'a pas : un appareil sans caissier, appairé
 * une fois, qui n'atteint QUE ses propres routes (pare-feu `kiosk`, `^/api/kiosk/`).
 *
 * `capability()` → `null` pour ce premier lot : une borne vend par le module de vente, qui n'est pas
 * une capacité optionnelle. En faire une option payante est une décision commerciale laissée à
 * Maxime (PR, « Décisions prises, à valider »).
 */
final class KioskModule implements ModuleManifest
{
    public function id(): string
    {
        return 'kiosk';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    public function capability(): ?string
    {
        return null;
    }

    /** @return list<string> */
    public function dependencies(): array
    {
        return [];
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return [
            'kiosk.read',
            'kiosk.manage',
        ];
    }

    /** @return list<string> */
    public function eventsEmitted(): array
    {
        return [];
    }

    /** @return list<string> */
    public function eventsConsumed(): array
    {
        return [];
    }

    /** @return list<string> */
    public function features(): array
    {
        return [];
    }

    /** @return list<string> */
    public function routes(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function settingsSchema(): array
    {
        return [];
    }
}
