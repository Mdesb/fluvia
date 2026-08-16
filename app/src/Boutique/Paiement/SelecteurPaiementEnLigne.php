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
 */
final class SelecteurPaiementEnLigne
{
    /** @var list<PaiementEnLigneInterface>|null */
    private ?array $adaptateurs = null;

    /**
     * @param iterable<PaiementEnLigneInterface> $adaptateurs
     */
    public function __construct(
        #[AutowireIterator('boutique.paiement_en_ligne')]
        private readonly iterable $adaptateursIterable,
    ) {
    }

    public function pour(ProfilExploitant $profil): PaiementEnLigneInterface
    {
        $liste = $this->liste();
        $cle = $profil->getType();

        foreach ($liste as $adaptateur) {
            if ($cle === TypeExploitant::RegieDirecte && $adaptateur->cle() === TypeExploitant::RegieDirecte) {
                return $adaptateur;
            }
        }

        // Dsp/GroupePrive partagent le même adaptateur privé (RG-M3-11) : premier adaptateur non
        // « RegieDirecte » trouvé dans l'itérateur taggé.
        if ($cle !== TypeExploitant::RegieDirecte) {
            foreach ($liste as $adaptateur) {
                if ($adaptateur->cle() !== TypeExploitant::RegieDirecte) {
                    return $adaptateur;
                }
            }
        }

        throw new UnprocessableEntityHttpException(sprintf('Aucun moyen de paiement en ligne enregistré pour le régime « %s ».', $cle->value));
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
