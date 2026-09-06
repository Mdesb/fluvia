<?php

declare(strict_types=1);

namespace App\Boutique\Paiement;

use App\Compta\Enum\TypeExploitant;
use App\Vente\Enum\StatutTPE;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Uid\Uuid;

/**
 * Régime « DSP / groupe privé » (RG-M3-11) : ⚠ **PSP CB non nommé par les sources** (Risque n°5 du
 * plan) — stub par défaut, contrat calqué par analogie stricte sur PayFiP (référence de transaction,
 * retour OK/échec/annulé, rapprochement).
 */
#[AutoconfigureTag('boutique.paiement_en_ligne')]
final class PspCbStubAdapter implements PaiementEnLigneInterface
{
    public function __construct(
        private readonly StubPaymentReceiptSigner $signer,
    ) {
    }

    public function cle(): TypeExploitant
    {
        // Un même stub couvre les deux discriminants privés (DSP et groupe privé, RG-M3-11) :
        // le Sélecteur les fait pointer explicitement vers cette clé (cf. SelecteurPaiementEnLigne).
        return TypeExploitant::GroupePrive;
    }

    public function initierPaiement(Uuid $venteId, int $montantCentimes, string $urlRetour): InitiationPaiementEnLigne
    {
        $reference = 'PSPCB-' . substr(hash('sha256', $venteId->toRfc4122() . $montantCentimes), 0, 16);

        return new InitiationPaiementEnLigne($reference, 'https://psp-cb.example.test/paiement/' . $reference, $this->signer->receipts($reference, $montantCentimes));
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
