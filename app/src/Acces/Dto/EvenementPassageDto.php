<?php

declare(strict_types=1);

namespace App\Acces\Dto;

use App\Acces\Enum\SensPassage;
use Symfony\Component\Uid\Uuid;

/**
 * DTO domaine (§2.1) : événement de passage remonté par le matériel (scan au lecteur) ou rejoué à la
 * synchro. Aucun champ propre au protocole matériel (OSDP/REST) n'y transparaît.
 *
 * Extension additive (plan-acces-terminal.md §4.1, US-TERM-02/06/08) : `horodatageBorne` et
 * `autoriserCreditNegatifSiHorsLigne` sont de nouveaux paramètres nommés avec des défauts inertes — tous
 * les appelants existants (`PassageIngestionProcessor`, `SynchroPassageHandler` tel quel) compilent et
 * se comportent à l'identique sans modification.
 */
final class EvenementPassageDto
{
    public function __construct(
        public readonly Uuid $equipementId,
        public readonly ?string $identifiantSupport,
        public readonly ?SensPassage $sens,
        public readonly \DateTimeImmutable $horodatage,
        public readonly Uuid $cleIdempotence,
        public readonly bool $origineHorsLigne = false,
        /** Réservé à la synchro (§4.6) : ne pas refuser sur une révocation postérieure au passage. */
        public readonly bool $ignorerRevocationSiPosterieure = false,
        /**
         * Horodatage tel que transmis par la borne (skew, §4.4 spec) : conservé tel quel à des fins de
         * traçabilité/supervision, distinct de `horodatage` qui reste la valeur utilisée pour le rejeu
         * chronologique. `null` = non applicable (flux en ligne agent humain).
         */
        public readonly ?\DateTimeImmutable $horodatageBorne = null,
        /**
         * Réservé à `App\Acces\Service\SynchroPassageHandler::synchroniserPourTerminal()` (§4.2 du plan,
         * CA-8) : autorise, uniquement pour ce chemin hors-ligne, un décompte de crédit `carte_quota`
         * jusqu'à un plancher négatif borné (réconciliation gracieuse d'un dépassement) au lieu du refus
         * strict habituel. **Jamais** positionné à `true` par le chemin en ligne
         * (`PassageIngestionProcessor`/`TerminalPassageProcessor`) : défaut `false` = comportement actuel
         * strictement inchangé (zéro régression observable sur `/acces/passages`/`/terminal/passages`).
         */
        public readonly bool $autoriserCreditNegatifSiHorsLigne = false,
    ) {
    }
}
