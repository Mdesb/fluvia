<?php

declare(strict_types=1);

namespace App\Ocr;

/**
 * Manifeste du service transverse `App\Ocr` (COORDINATION/CONTRACT/manifeste-module.md). Aucune
 * interface `ModuleManifest`/registre `App\Platform\Module` n'existe encore dans ce dépôt (brique
 * « à créer » du noyau commun, cf. noyau-commun.md — hors périmètre de ce lot) : cette classe respecte
 * déjà la « forme cible » documentée (mêmes noms de méthode), prête à implémenter l'interface dès
 * qu'elle existera, sans changement de signature attendu.
 *
 * `App\Ocr` est un **service partagé** (pas un module métier activable par tenant au sens strict, au
 * même titre que « Communication »/« Automation ») : consommé en PHP par les modules Finance
 * (FIN-2 Supplier invoices, FIN-3 Expense reports) via l'interface `DocumentExtractor`, sans
 * dépendance dure dans l'autre sens (spec-ocr.md §2/§8). Aucun événement émis (§7 catalogue-evenements.md).
 */
final class OcrModule
{
    public function id(): string
    {
        return 'ocr';
    }

    public function version(): string
    {
        return '0.1.0';
    }

    public function capacite(): string
    {
        return 'ocr';
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
