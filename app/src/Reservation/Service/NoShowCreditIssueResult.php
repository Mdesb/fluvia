<?php

declare(strict_types=1);

namespace App\Reservation\Service;

use Symfony\Component\Uid\Uuid;

/**
 * Résultat de l'application d'une `IssueCreditNoShow` (RG-CQ5-04/05, plan-cq5.md §3.5) — pas une
 * entité, jamais persisté.
 *
 * Nom entièrement anglais (D5, fichier neuf) : le plan proposait `ResultatIssueCreditNoShow`, mais
 * « résultat » figure au lexique français surveillé par `bin/garde-fou-nommage-anglais.php` — renommé
 * ici pour un fichier réellement nouveau (cf. rapport d'implémentation, écart documenté au plan).
 */
final class NoShowCreditIssueResult
{
    private function __construct(
        public readonly bool $creditActioned,
        public readonly bool $creditRestored,
        public readonly ?Uuid $droitId,
    ) {
    }

    /** RG-CQ5-04 : aucun `DroitAcces` créditable trouvé (pas de projection, ou `creditRestant === null`). */
    public static function noCredit(): self
    {
        return new self(false, false, null);
    }

    /** RG-CQ5-05 issue `Decremented` : un droit créditable existe, aucune écriture supplémentaire. */
    public static function decremented(Uuid $droitId): self
    {
        return new self(true, false, $droitId);
    }

    /** RG-CQ5-05 issue `Restored`/`RestoredWithReschedule`, écriture réussie. */
    public static function restored(Uuid $droitId): self
    {
        return new self(true, true, $droitId);
    }

    /**
     * RG-CQ5-06 — un droit créditable a bien été trouvé, mais l'UPDATE atomique n'a affecté aucune
     * ligne (le crédit a disparu entre la lecture et l'écriture : course concurrente, chemin
     * théorique aujourd'hui, réel dès qu'un autre écrivain touchera `creditRestant`). `creditActioned`
     * reste true (un droit existait), `creditRestored` false : traçable et distinct de `noCredit()`
     * (« aucun droit créditable n'existait »), comme le documente le plan §8 risque n°5.
     */
    public static function raceLost(Uuid $droitId): self
    {
        return new self(true, false, $droitId);
    }
}
