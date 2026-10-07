<?php

declare(strict_types=1);

namespace App\Fonctionnalite\Service;

use App\Platform\Module\ModuleRegistry;

/**
 * Résout le mot AFFICHÉ pour une clé de vocabulaire, selon la verticale du contexte (D15, #100).
 *
 * ── TROIS COUCHES, LA DERNIÈRE PRÉSENTE L'EMPORTE ───────────────────────────────────────────────
 *
 *   1. défaut FR (ici) — toujours présent, donc jamais de clé nue à l'écran ;
 *   2. surcharge verticale — `settingsSchema()['vocabulary']` du manifeste (patinoire : `slot` =>
 *      « Séance de glace ») ;
 *   3. surcharge établissement — l'exploitant renomme (D15). HORS lot 1 : voir
 *      `features/resolveur-vocabulaire/specs/`.
 *
 * ── ⚠ CONTEXTUEL PAR RESSOURCE (option B, CP-1 du 14/09) ────────────────────────────────────────
 *
 * Le mot dépend de la verticale de la RESSOURCE affichée, pas de l'établissement : un créneau sur un
 * terrain de padel dit « Réservation de terrain », sur un bassin « Créneau public », dans le même
 * établissement mixte. D'où le paramètre `$verticale` (l'`id` du module verticale, ex. « padel »).
 *
 * ── ⚠ LA SOURCE DE VÉRITÉ DES MOTS RESTE LES MANIFESTES ─────────────────────────────────────────
 *
 * Seul le DÉFAUT FR vit ici. Les mots par verticale sont lus dans les manifestes via `ModuleRegistry`
 * — les recopier ferait diverger (ce que `specs/verticales/vocabulaire.md` interdit explicitement).
 */
final class VocabularyResolver
{
    /**
     * Les douze clés du catalogue et leur défaut français. Clés courtes, comme dans `settingsSchema()`.
     *
     * @var array<string, string>
     */
    private const DEFAULTS = [
        'resource' => 'Ressource',
        'resource_unit' => 'Unité',
        'slot' => 'Créneau',
        'booking' => 'Réservation',
        'participant' => 'Participant',
        'staff' => 'Encadrant',
        'group' => 'Groupe',
        'entry' => 'Entrée',
        'multi_entry_card' => 'Carte multi-entrées',
        'capacity' => 'Jauge',
        'rental' => 'Location',
        'deposit' => 'Caution',
    ];

    public function __construct(private readonly ModuleRegistry $registry)
    {
    }

    /**
     * Le mot affiché pour cette clé, dans le contexte de cette verticale (id de module).
     *
     * `$verticale` nul ou inconnu, ou clé non surchargée par la verticale : on retombe sur le défaut
     * FR. Une clé hors catalogue est rendue telle quelle — un appelant qui se trompe de clé le voit,
     * plutôt qu'un vide silencieux.
     */
    public function label(string $key, ?string $verticale = null): string
    {
        $default = self::DEFAULTS[$key] ?? $key;

        if ($verticale === null || !$this->registry->has($verticale)) {
            return $default;
        }

        $vocabulary = $this->registry->get($verticale)->settingsSchema()['vocabulary'] ?? [];
        $mot = \is_array($vocabulary) ? ($vocabulary[$key] ?? null) : null;

        return \is_string($mot) && $mot !== '' ? $mot : $default;
    }

    /**
     * Toutes les clés du catalogue résolues pour une verticale — la table que l'API sert au frontend.
     *
     * @return array<string, string>
     */
    public function table(?string $verticale = null): array
    {
        $table = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            $table[$key] = $this->label($key, $verticale);
        }

        return $table;
    }

    /**
     * Les clés connues, pour que l'appelant sache ce qu'il peut demander.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys(self::DEFAULTS);
    }
}
