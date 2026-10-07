<?php

declare(strict_types=1);

namespace App\Compta\Enum;

/**
 * Les categories de taux que la directive TVA europeenne distingue.
 *
 * ⚠ CE SONT DES CATEGORIES JURIDIQUES, PAS DES NIVEAUX. `Zero` et `Exempt` rendent tous deux zero
 * euro de TVA et ne sont PAS la meme chose : un taux zero ouvre droit a deduction pour le vendeur,
 * une exoneration ne l'ouvre pas. Les confondre change ce que l'exploitant peut recuperer, et la
 * difference ne se voit pas sur la facture du client.
 *
 * `OutOfScope` n'est pas un taux du tout : c'est « cette operation n'entre pas dans le champ de la
 * TVA ». Il figure ici parce que les exploitants le saisissent aujourd'hui a la main comme s'il en
 * etait un — mieux vaut lui donner sa place que le laisser se deguiser en taux a 0 %.
 */
enum VatRateCategory: string
{
    /** Le taux normal du pays. */
    case Standard = 'standard';
    /** Taux reduit, prevu par l'annexe III de la directive. */
    case Reduced = 'reduced';
    /** Second taux reduit, la ou le pays en applique deux. */
    case SecondReduced = 'second_reduced';
    /** Taux super-reduit (moins de 5 %), heritage historique autorise a certains Etats. */
    case SuperReduced = 'super_reduced';
    /** Taux « parking », entre 12 % et le taux normal, sur des biens limitativement enumeres. */
    case Parking = 'parking';
    /** Taux zero AVEC droit a deduction. */
    case Zero = 'zero';
    /** Exoneration SANS droit a deduction. */
    case Exempt = 'exempt';
    /** Hors du champ d'application de la TVA. */
    case OutOfScope = 'out_of_scope';

    /** Rend-elle zero euro de TVA ? Vrai pour trois categories qui ne se valent pas juridiquement. */
    public function isZeroRated(): bool
    {
        return \in_array($this, [self::Zero, self::Exempt, self::OutOfScope], true);
    }

    /**
     * La categorie EN 16931 (BT-151) que porte une facture reprenant ce taux legal.
     *
     * ⚠ LES CINQ CATEGORIES A TAUX POSITIF TOMBENT TOUTES SUR `S`, ET CE N'EST PAS UN RACCOURCI.
     * La norme europeenne ne code PAS le niveau du taux : elle code sa NATURE. Un taux normal, un
     * taux reduit, un super-reduit et un parking sont tous « standard rated » au sens de BT-151 —
     * ce qui les distingue sur la facture est la valeur du taux (BT-152), pas la categorie. Cette
     * regle etait deja ecrite en commentaire dans `StructureOnboarding` ; elle vit ici desormais,
     * parce qu'un commentaire ne se teste pas et ne se reutilise pas.
     *
     * Les trois categories a zero euro, elles, se separent — et c'est tout l'interet de ne pas les
     * avoir aplaties : `Zero` ouvre droit a deduction (`Z`), `Exempt` non (`E`), et `OutOfScope`
     * n'est pas une operation taxable du tout (`O`). Les rendre toutes `Z` ferait declarer comme
     * chiffre d'affaires taxe a 0 % ce qui n'entre pas dans le champ.
     */
    public function toInvoiceCategory(): VatCategory
    {
        return match ($this) {
            self::Standard, self::Reduced, self::SecondReduced, self::SuperReduced, self::Parking => VatCategory::Standard,
            self::Zero => VatCategory::ZeroRated,
            self::Exempt => VatCategory::Exempt,
            self::OutOfScope => VatCategory::OutOfScope,
        };
    }
}
