<?php

declare(strict_types=1);

namespace App\Ocr;

use App\Platform\Module\ModuleManifest;

/**
 * Manifeste du service transverse `App\Ocr` — implémente `App\Platform\Module\ModuleManifest`
 * (registre du noyau, `da3cb6d`), donc enregistré automatiquement par le tag de l'interface.
 *
 * `App\Ocr` est un **service partagé** (consommé en PHP par les modules Finance via `DocumentExtractor`,
 * spec-ocr.md §2/§8 ; aucun événement émis). ⚠ **Point à arbitrer par l'intégrateur (D4)** :
 * `capability()` renvoie ici `'ocr'` pour satisfaire la signature `string` de l'interface, mais D4 pose
 * qu'OCR est transverse et non activable par tenant. Si le registre doit distinguer les services
 * transverses des modules métier (capacité nulle / marqueur dédié), c'est un ajustement de contrat côté
 * `App\Platform` — je m'aligne sur ta décision.
 */
final class OcrModule implements ModuleManifest
{
    public function id(): string
    {
        return 'ocr';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    public function capability(): ?string
    {
        // `null` = service transverse partagé (D4, entériné par A : `ModuleManifest::capability(): ?string`).
        // OCR n'est ni vendu ni activable par établissement — consommé en PHP par les modules Finance.
        // `ModuleAccess::hasFeature()` garde alors les features par leur seule activation propre.
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
        return ['ocr.configure', 'ocr.read_extraction'];
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
    public function routes(): array
    {
        // Pas d'écran dédié : consommé exclusivement en PHP par FIN-2/FIN-3 (spec-ocr.md §2). Les 2
        // ressources API (`OcrProviderConfig`, `ExtractionAttempt`) sont des points d'intégration
        // techniques, pas une navigation utilisateur `App\Ocr` propre.
        return [];
    }

    /** @return array<string, mixed> */
    public function settingsSchema(): array
    {
        return [
            'provider' => ['type' => 'enum', 'values' => ['manual', 'anthropic'], 'default' => 'manual'],
            'confidenceThreshold' => ['type' => 'float', 'min' => 0.0, 'max' => 1.0, 'default' => 0.7],
        ];
    }

    /** @return list<string> */
    public function features(): array
    {
        return [];
    }
}
