<?php

declare(strict_types=1);

namespace App\Boutique\Paiement;

use Symfony\Component\Uid\Uuid;

/**
 * Paiement en ligne commuté PayFiP / PSP CB (US-L8-07, RG-M3-11). Chaque implémentation est taguée
 * `boutique.paiement_en_ligne` et résolue par `SelecteurPaiementEnLigne` selon
 * `ProfilExploitant.type` (RG-M6-01) — seul point de lecture de ce discriminant côté Boutique.
 */
interface PaiementEnLigneInterface
{
    /** Discriminant d'enregistrement — lu uniquement par le Sélecteur. */
    public function cle(): \App\Compta\Enum\TypeExploitant;

    public function initierPaiement(Uuid $venteId, int $montantCentimes, string $urlRetour): InitiationPaiementEnLigne;

    /**
     * Le résultat VÉRIFIÉ d'un retour de paiement — ou `null` quand rien ne l'atteste.
     *
     * ⚠ AUDIT DU 06/09, CONSTAT 1. L'ancien `traiterRetour(array)` lisait `statut` dans les données
     * reçues et le rendait tel quel : le statut venait du corps de la requête, donc de l'acheteur. Un
     * `{"statut":"accepte"}` confirmait la commande. Le contrat dit désormais ce qu'un adaptateur DOIT
     * faire : vérifier — une signature du prestataire, ou un appel serveur à serveur sur la référence —
     * et rendre `null` s'il ne peut pas. Le processeur refuse alors (422), il ne devine jamais.
     *
     * `$montantAttenduCentimes` est le montant mémorisé à l'initiation : un adaptateur qui signe le
     * montant le vérifie ici ; le processeur le revérifie de toute façon sur le résultat rendu.
     *
     * @param array<string, mixed> $donneesRetour ce que le retour transporte (reçu, signature, jeton…) — jamais une décision
     */
    public function verifierRetour(string $referenceTransaction, int $montantAttenduCentimes, array $donneesRetour): ?ResultatRetourPaiementEnLigne;
}
