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
}
