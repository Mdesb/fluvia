<?php

declare(strict_types=1);

namespace App\Vente\Nf525\Dto;

use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Une journée qui aurait dû être arrêtée et ne l'a pas été (D57, D55).
 *
 * **Ce n'est pas une ligne de journal, c'est une file d'attente.** Le mécanisme qui repère une clôture
 * manquée existait déjà — c'est le refus « journée sautée » du `DailyClosureHandler`. Ce qui manquait,
 * c'est que **personne ne le voie** tant que quelqu'un ne tentait pas une clôture. Une liste qui
 * descend à zéro se lit ; un journal d'erreurs finit par ne plus être ouvert.
 *
 * D'où `raison` : sans elle, on cherche au mauvais endroit. Une journée non close parce que le
 * traitement de nuit a échoué et une journée non close parce qu'elle en précède une autre déjà
 * arrêtée n'appellent pas le même geste.
 */
final class PendingClosure
{
    public function __construct(
        #[Groups(['pending_closure:read'])]
        public readonly string $pointDeVente,
        #[Groups(['pending_closure:read'])]
        public readonly string $libelle,
        #[Groups(['pending_closure:read'])]
        public readonly string $etablissement,
        #[Groups(['pending_closure:read'])]
        public readonly string $fuseauHoraire,
        /** La journée à arrêter, dans le fuseau du point de vente. */
        #[Groups(['pending_closure:read'])]
        public readonly string $journee,
        #[Groups(['pending_closure:read'])]
        public readonly int $nombreVentes,
        /** Depuis combien de jours elle attend — un « en retard de 12 j » se lit sans soustraire (D46). */
        #[Groups(['pending_closure:read'])]
        public readonly int $joursDeRetard,
        #[Groups(['pending_closure:read'])]
        public readonly string $raison,
    ) {
    }
}
