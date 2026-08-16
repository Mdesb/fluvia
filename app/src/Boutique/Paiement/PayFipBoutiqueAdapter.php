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
    ) {
    }

    public function cle(): TypeExploitant
    {
        return TypeExploitant::RegieDirecte;
    }

    public function initierPaiement(Uuid $venteId, int $montantCentimes, string $urlRetour): InitiationPaiementEnLigne
    {
        $resultat = $this->payFip->initierPaiement((string) $venteId, $montantCentimes);

        return new InitiationPaiementEnLigne($resultat->referenceTransaction, $resultat->urlRedirection);
    }

    public function traiterRetour(array $donneesRetour): ResultatRetourPaiementEnLigne
    {
        $reference = \is_string($donneesRetour['referenceTransaction'] ?? null) ? $donneesRetour['referenceTransaction'] : '';
        $statut = StatutTPE::tryFrom((string) ($donneesRetour['statut'] ?? '')) ?? StatutTPE::Timeout;
        $montant = (int) ($donneesRetour['montantCentimes'] ?? 0);

        return new ResultatRetourPaiementEnLigne($reference, $statut, $montant);
    }
}
