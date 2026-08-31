<?php

declare(strict_types=1);

namespace App\Acces\Dto;

use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Support;
use App\Acces\Enum\CodeMotifRefus;

/**
 * La réponse à « CE BILLET EST-IL VALIDE ? » — et à rien d'autre.
 *
 * ⚠ CE QUE CET OBJET NE DIT PAS, ET NE DOIT JAMAIS DIRE : si le porteur peut franchir une porte
 * DONNÉE. C'est une seconde question (D86), qui demande un équipement, un espace, une zone déclarée,
 * une jauge et un anti-passback — rien de tout cela n'existe quand un agent contrôle un billet à la
 * main sur un site sans matériel.
 *
 * Les deux questions vivaient confondues dans `ValidationPassageHandler`, non par négligence : il
 * les traitait déjà dans cet ordre, mais son entrée EXIGE un équipement dès sa première ligne. Rien
 * ne permettait donc de poser la première sans la seconde, et c'est exactement ce que Maxime a
 * nommé — « certains n'ont pas de contrôle d'accès, mais le billet pourra être quand même validé
 * par un contrôle manuel ».
 *
 * ⚠ UNE SEULE RÈGLE, DEUX ENTRÉES. Ce DTO existe pour que le contrôle manuel et le franchissement
 * partagent la même décision au lieu de la réimplémenter. Deux implémentations divergentes de « ce
 * billet est-il valide » seraient pires que pas d'outil du tout : elles se contrediraient un jour,
 * sur un porteur, devant une porte, et personne ne saurait laquelle a raison.
 */
final class VerdictBillet
{
    private function __construct(
        public readonly bool $valide,
        public readonly ?CodeMotifRefus $codeMotif,
        /** Phrase destinée à l'exploitant, pas au journal technique. */
        public readonly string $message,
        public readonly ?Support $support,
        public readonly ?DroitAcces $droit,
        /**
         * Révocation postérieure au passage rejoué hors ligne : le support est bloqué AUJOURD'HUI
         * mais ne l'était pas au moment du franchissement. Ce n'est pas un refus, c'est un conflit
         * à tracer — le passage est conservé (§4.6).
         */
        public readonly bool $enConflitRevocation,
    ) {
    }

    public static function valide(Support $support, DroitAcces $droit, bool $enConflitRevocation = false): self
    {
        return new self(true, null, 'Billet valide.', $support, $droit, $enConflitRevocation);
    }

    /**
     * ⚠ Le support et le droit sont rendus MÊME EN CAS DE REFUS quand ils ont pu être résolus.
     * Sans eux, l'appelant ne peut pas écrire un passage qui dit à QUI le refus s'applique — et un
     * refus qu'on ne peut pas rattacher à un porteur ne s'explique pas au comptoir.
     */
    public static function refuse(
        CodeMotifRefus $codeMotif,
        string $message,
        ?Support $support = null,
        ?DroitAcces $droit = null,
    ): self {
        return new self(false, $codeMotif, $message, $support, $droit, false);
    }
}
