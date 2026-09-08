<?php

declare(strict_types=1);

namespace App\Sepa\Dto;

use Symfony\Component\Uid\Uuid;

/**
 * Échéance due, fournie au module SEPA par une verticale via `EcheanceSepaSource` (plan §3/§5).
 * `referenceOrigine` est un identifiant opaque (choisi par la verticale, ex. UUID de son échéance)
 * qui lui est retourné par `EcheanceSepaSource::marquerCollectees()` — le module SEPA ne connaît
 * aucun type métier de la verticale appelante (Sport, Piscine…).
 */
final class EcheanceSepaDue
{
    public function __construct(
        public readonly string $referenceOrigine,
        public readonly Uuid $mandatId,
        public readonly int $montantCentimes,
        public readonly string $libelle,
        public readonly \DateTimeImmutable $dateEcheance,
        public readonly bool $derniereEcheanceEngagement = false,
        public readonly bool $paiementUnique = false,
        /**
         * Taux de TVA applicable à cette échéance, en valeur décimale (« 20.00 »), tel que la
         * verticale le connaît — `null` quand elle ne sait pas le dire.
         *
         * ⚠ AJOUTÉ ICI PLUTÔT QUE DANS UN SECOND PORT, DÉLIBÉRÉMENT. La facturation des échéances
         * (chaine-encaissement G-1) a besoin d'un taux, et les verticales sont les seules à le
         * connaître. Créer un port d'énumération parallèle aurait dupliqué la requête qui liste les
         * échéances dues, et les deux listes auraient divergé au premier correctif apporté à une
         * seule des deux.
         *
         * ⚠ `null` N'EST PAS UN DÉFAUT À COMBLER PAR UNE VALEUR RAISONNABLE. Une échéance sans taux
         * connu fait REFUSER l'émission de sa facture, en nommant la formule en cause : un taux
         * inventé partirait dans une facture scellée, qui ne se corrige plus — elle s'avoire.
         */
        public readonly ?string $tauxTvaValeur = null,
    ) {
    }
}
