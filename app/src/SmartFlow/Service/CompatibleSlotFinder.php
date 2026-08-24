<?php

declare(strict_types=1);

namespace App\SmartFlow\Service;

use App\SmartFlow\Dto\SlotSnapshot;

/**
 * RG-SF-09 (plan-smart-flow.md §0.6) : un créneau est **compatible** avec le créneau d'origine s'il a
 * **même ressource OU même `codeType`** (union, pas intersection — maximise le taux de report réussi,
 * ⚠ tolérance par défaut à confirmer claude-A, configurable via `SmartFlowModule::settingsSchema()`
 * → `toleranceLevel`), avec une capacité résiduelle strictement positive au moment de la recherche.
 *
 * Fonction pure : ne fait aucune lecture, opère sur des `SlotSnapshot` déjà résolus par
 * `ReservationSlotReader::findCandidateSlots()` (fenêtre/établissement déjà appliqués par l'appelant) —
 * testable sans base de données.
 */
final class CompatibleSlotFinder
{
    /**
     * Premier créneau compatible trouvé parmi `$candidates` (ordre déjà pertinent côté appelant), ou
     * `null` si aucun (RG-SF-11 : la proposition reste `searching`, pas un échec silencieux).
     *
     * @param list<SlotSnapshot> $candidates
     */
    public function selectCompatible(SlotSnapshot $origin, array $candidates): ?SlotSnapshot
    {
        foreach ($candidates as $candidate) {
            if ($candidate->id->equals($origin->id)) {
                continue; // jamais reproposer le créneau d'origine lui-même.
            }
            if ($candidate->residualCapacity <= 0) {
                continue;
            }
            if ($this->isCompatible($origin, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function isCompatible(SlotSnapshot $origin, SlotSnapshot $candidate): bool
    {
        $sameResource = $origin->resourceId !== null
            && $candidate->resourceId !== null
            && $origin->resourceId->equals($candidate->resourceId);

        $sameType = $origin->codeType !== null
            && $candidate->codeType !== null
            && $origin->codeType === $candidate->codeType;

        return $sameResource || $sameType;
    }
}
