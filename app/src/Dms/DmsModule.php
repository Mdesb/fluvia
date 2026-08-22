<?php

declare(strict_types=1);

namespace App\Dms;

use App\Platform\Module\ModuleManifest;

/**
 * Manifeste du service transverse `App\Dms` (GED, plan-dms.md §8) — même famille que `App\Ocr\OcrModule`
 * (docblock d'`OcrModule` cite déjà « OCR, GED, signature, i18n » comme services transverses). `App\Dms`
 * n'est **ni vendu ni activable par établissement** : `capability()` renvoie `null` (spec-dms.md §1,
 * arbitrage D18 pt.1).
 *
 * `eventsEmitted()` reproduit **exactement** les 8 lignes déjà catalogées
 * (`COORDINATION/CONTRACT/catalogue-evenements.md:66-73`) — aucune ligne ajoutée au contrat par ce lot
 * (RG-DMS-21/RG-PLAT-06 déjà satisfaites avant l'implémentation).
 */
final class DmsModule implements ModuleManifest
{
    public function id(): string
    {
        return 'dms';
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
            'dms.read',
            'dms.write',
            'dms.delete',
            'dms.manage_retention',
            'dms.manage_public_link',
        ];
    }

    /** @return list<string> */
    public function eventsEmitted(): array
    {
        return [
            'document.stored',
            'document.version_added',
            'document.retention_set',
            'document.deletion_refused',
            'document.deleted',
            'document.purged',
            'document.public_link_issued',
            'document.public_link_revoked',
        ];
    }

    /** @return list<string> */
    public function eventsConsumed(): array
    {
        // `intervention.validated` : futur module non construit (spec-dms.md §11) — activé quand ce
        // module existera, pas anticipé ici (même prudence que `FinanceModule`).
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
        return ['/dms/documents']; // écran unique, RG-DMS-26.
    }

    /** @return array<string, mixed> */
    public function settingsSchema(): array
    {
        return [];
    }
}
