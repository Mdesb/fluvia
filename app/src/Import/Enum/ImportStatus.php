<?php

declare(strict_types=1);

namespace App\Import\Enum;

/**
 * Où en est un lot de reprise (SPEC-REPRISE-INITIALE §2).
 *
 * **Le cycle a deux temps, et c'est la décision « tout refuser » qui les impose.** Un import qui
 * écrit et valide en même temps ne peut pas tout refuser : quand il découvre la ligne 4 217, les
 * 4 216 premières sont déjà en base. Séparer l'analyse de l'application donne le refus total *et*
 * la prévisualisation, sans qu'on ait à choisir entre les deux.
 *
 *     Pending ──analyse──> Validated ──application──> Applied ──annulation──> Reverted
 *         │                                              │
 *         └──────────────> Rejected                      └── refusée si une ligne a servi
 */
enum ImportStatus: string
{
    /** Déposé, pas encore analysé. État transitoire : l'analyse suit le dépôt. */
    case Pending = 'pending';

    /** Analysé, au moins une ligne fautive. **Rien n'a été écrit en base métier.** */
    case Rejected = 'rejected';

    /** Analysé, tout est bon. Toujours rien en base métier : l'application est un second geste. */
    case Validated = 'validated';

    /** Appliqué, en une transaction. Chaque ligne créée porte la référence de ce lot. */
    case Applied = 'applied';

    /** Annulé : ce que ce lot avait créé a été supprimé. */
    case Reverted = 'reverted';

    /** Un lot ne s'applique que depuis `validated` — jamais depuis `pending`, jamais deux fois. */
    public function canBeApplied(): bool
    {
        return $this === self::Validated;
    }

    /**
     * Un lot ne s'annule que depuis `applied`.
     *
     * Annuler un lot rejeté ou validé n'aurait rien à défaire : il n'a jamais rien écrit. Le refuser
     * plutôt que de l'accepter sans effet évite de laisser croire qu'une annulation a eu lieu.
     */
    public function canBeReverted(): bool
    {
        return $this === self::Applied;
    }
}
