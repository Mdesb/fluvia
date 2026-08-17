<?php

declare(strict_types=1);

namespace App\Stock\Enum;

/**
 * Typologie fermée des mouvements de stock (RG-STOCK-07). Le signe (entrée/sortie) est porté par le
 * type, jamais par la valeur de `quantite` (toujours positive).
 */
enum TypeMouvementStock: string
{
    case EntreeAchat = 'entree_achat';
    case SortieVente = 'sortie_vente';
    case RetourFournisseur = 'retour_fournisseur';
    case AjustementPositif = 'ajustement_positif';
    case AjustementNegatif = 'ajustement_negatif';
    case PerteCasse = 'perte_casse';
    case SortieTransfert = 'sortie_transfert';
    case EntreeTransfert = 'entree_transfert';
    case RegularisationInventaire = 'regularisation_inventaire';

    /** Vrai si le mouvement incrémente la disponibilité (entrée). */
    public function estEntree(): bool
    {
        return match ($this) {
            self::EntreeAchat, self::AjustementPositif, self::EntreeTransfert => true,
            default => false,
        };
    }

    /** Vrai si le mouvement décrémente la disponibilité (sortie), consommant des couches FIFO/LIFO. */
    public function estSortie(): bool
    {
        return !$this->estEntree();
    }

    /** RG-STOCK-07 : motif obligatoire sauf pour entrée_achat / sortie_vente. */
    public function motifRequis(): bool
    {
        return $this !== self::EntreeAchat && $this !== self::SortieVente;
    }
}
