<?php

declare(strict_types=1);

namespace App\Boutique\Paiement;

use App\Compta\Enum\TypeExploitant;
use App\Compta\Port\PayFipInterface;
use App\Vente\Enum\StatutTPE;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Uid\Uuid;

/**
 * Régime « régie directe / public » (RG-M3-11) : **enveloppe directement** `App\Compta\Port\
 * PayFipInterface` (code réel M6, non modifié) — pas de réécriture du protocole PayFiP.
 */
#[AutoconfigureTag('boutique.paiement_en_ligne')]
final class PayFipBoutiqueAdapter implements PaiementEnLigneInterface
{
    public function __construct(
        private readonly PayFipInterface $payFip,
        private readonly StubPaymentReceiptSigner $signer,
    ) {
    }

    public function cle(): TypeExploitant
    {
        return TypeExploitant::RegieDirecte;
    }

    public function initierPaiement(Uuid $venteId, int $montantCentimes, string $urlRetour): InitiationPaiementEnLigne
    {
        $resultat = $this->payFip->initierPaiement((string) $venteId, $montantCentimes);

        // ⚠ Tant que `PayFipInterface` est un bouchon (« rien avec PayFiP pour le moment »), le retour se
        //   vérifie par le même reçu signé que le PSP CB. Le jour du vrai PayFiP, c'est SON contrôle
        //   (signature du retour, ou interrogation de la transaction) qui remplacera le signataire.
        return new InitiationPaiementEnLigne($resultat->referenceTransaction, $resultat->urlRedirection, $this->signer->receipts($resultat->referenceTransaction, $montantCentimes));
    }

    /**
     * Bouchon : n'atteste que ce qu'il a lui-même signé à l'initiation, pour cette référence et ce
     * montant (`StubPaymentReceiptSigner`). Un statut nu dans les données n'est pas lu — c'était le
     * défaut (audit 06/09, constat 1). Le vrai adaptateur vérifiera la signature du prestataire ici.
     */
    public function verifierRetour(string $referenceTransaction, int $montantAttenduCentimes, array $donneesRetour): ?ResultatRetourPaiementEnLigne
    {
        $statut = $this->signer->verify($referenceTransaction, $montantAttenduCentimes, $donneesRetour['recu'] ?? null);
        if ($statut === null) {
            return null;
        }

        return new ResultatRetourPaiementEnLigne($referenceTransaction, $statut, $montantAttenduCentimes);
    }
}
