<?php

declare(strict_types=1);

namespace App\Boutique\Paiement;

use App\Compta\Entity\ProfilExploitant;
use App\Compta\Enum\TypeExploitant;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Construit le moyen de paiement en ligne applicable à partir de l'itérateur taggé
 * `boutique.paiement_en_ligne` (aucun `switch`, même patron que `RegimeComptableResolver`, code réel
 * M6). **Seul point de lecture** de `ProfilExploitant::type` côté Boutique (RG-M3-11) : `RegieDirecte`
 * → PayFiP, `Dsp`/`GroupePrive` → PSP CB (même adaptateur privé, contrat calqué par analogie).
 *
 * #339 — DEUX ADAPTATEURS PAR RÉGIME, ET UN ORDRE. Chaque régime a désormais un vrai prestataire
 * (`PayFipOnlinePaymentAdapter`, `StripeCheckoutAdapter`) ET son bouchon de démonstration. Le vrai
 * passe devant dès que sa configuration est complète (`ConfigurableOnlinePaymentInterface`) ; sinon
 * le bouchon reste, et il refuse lui-même de servir hors préproduction. L'ordre de l'itérateur ne
 * décide donc plus de rien : avant, « le premier adaptateur non régie » gagnait, et l'ajout d'un
 * second adaptateur privé aurait choisi au hasard de l'ordre d'enregistrement.
 */
final class SelecteurPaiementEnLigne
{
    /** @var list<PaiementEnLigneInterface>|null */
    private ?array $adaptateurs = null;

    /**
     * @param iterable<PaiementEnLigneInterface> $adaptateursIterable
     */
    public function __construct(
        #[AutowireIterator('boutique.paiement_en_ligne')]
        private readonly iterable $adaptateursIterable,
    ) {
    }

    public function pour(ProfilExploitant $profil): PaiementEnLigneInterface
    {
        $cle = $profil->getType();
        $public = $cle === TypeExploitant::RegieDirecte;

        $repli = null;
        foreach ($this->liste() as $adaptateur) {
            // Dsp/GroupePrive partagent le même adaptateur privé (RG-M3-11).
            if (($adaptateur->cle() === TypeExploitant::RegieDirecte) !== $public) {
                continue;
            }
            if ($adaptateur instanceof ConfigurableOnlinePaymentInterface) {
                if ($adaptateur->isConfigured()) {
                    return $adaptateur;
                }
                continue;
            }
            $repli ??= $adaptateur;
        }

        if ($repli !== null) {
            return $repli;
        }

        throw new UnprocessableEntityHttpException(sprintf('Aucun moyen de paiement en ligne enregistré pour le régime « %s ».', $cle->value));
    }

    /**
     * L'adaptateur qui reçoit les notifications de ce prestataire (`payfip`, `stripe`), s'il est
     * configuré — `null` sinon : une notification pour un prestataire éteint n'a rien à réveiller.
     */
    public function pourNotification(string $provider): ?NotifiedOnlinePaymentInterface
    {
        foreach ($this->liste() as $adaptateur) {
            if ($adaptateur instanceof NotifiedOnlinePaymentInterface
                && $adaptateur->provider() === $provider
                && (!$adaptateur instanceof ConfigurableOnlinePaymentInterface || $adaptateur->isConfigured())) {
                return $adaptateur;
            }
        }

        return null;
    }

    /** @return list<PaiementEnLigneInterface> */
    private function liste(): array
    {
        if ($this->adaptateurs !== null) {
            return $this->adaptateurs;
        }

        $liste = [];
        foreach ($this->adaptateursIterable as $adaptateur) {
            $liste[] = $adaptateur;
        }

        return $this->adaptateurs = $liste;
    }
}
