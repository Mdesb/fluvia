<?php

declare(strict_types=1);

namespace App\Vente\Nf525;

use App\Vente\Nf525\Entity\OperationScellee;

/**
 * Port d'inaltérabilité NF525 (US-L2-11) — enfichable pour ne pas figer un procédé cryptographique
 * que seul un référent conformité peut valider (⚠ point ouvert n°1). L'implémentation par défaut
 * (HashChainSignataire) utilise un chaînage sha256 + HMAC placeholder ; le passage à une signature
 * asymétrique (clé privée d'établissement, horodatage qualifié) est un simple changement d'impl.
 */
interface SignataireOperation
{
    /**
     * Scelle une opération : calcule l'empreinte à partir du payload canonicalisé et de l'empreinte
     * précédente, produit la signature et attribue numeroSequence = precedente + 1 (1 si génésis).
     * Ne persiste pas : renvoie l'entité prête à être insérée dans la transaction de validation.
     */
    public function scelle(OperationAScellerDto $op, ?OperationScellee $precedente): OperationScellee;

    /**
     * Recalcule chaque maillon d'une chaîne (ordonnée par numeroSequence) et détecte les ruptures :
     * trou de séquence, empreinte incohérente, signature invalide → alerte de contrôle (CA-15).
     *
     * @param iterable<OperationScellee> $operations
     */
    public function verifieChaine(iterable $operations): RapportVerification;
}
