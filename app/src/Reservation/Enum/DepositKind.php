<?php

declare(strict_types=1);

namespace App\Reservation\Enum;

/**
 * La nature juridique du versement demandé à la réservation — ARRHES ET ACOMPTE NE SONT PAS LA MÊME
 * CHOSE, et la différence commande le code, pas seulement le vocabulaire.
 *
 * ── ACOMPTE (`PartPayment`) ─────────────────────────────────────────────────────────────────────
 *
 * Une avance SUR le prix. L'engagement est ferme : personne ne peut se dédire. Le client qui ne
 * vient pas reste devoir le solde, et ce qu'il a versé s'impute dessus.
 *
 *   → une `FacturationNoShow` est émise POUR LE SOLDE : montant calculé par la règle, MOINS ce qui
 *     a réellement été retenu.
 *
 * ── ARRHES (`EarnestMoney`) ─────────────────────────────────────────────────────────────────────
 *
 * Chacun peut se dédire (art. 1590 du Code civil). Le client qui ne vient pas PERD son versement,
 * et c'est fini — on ne lui réclame rien de plus.
 *
 *   → AUCUNE `FacturationNoShow` n'est émise dès lors qu'un versement a été retenu. Le dédit EST la
 *     compensation.
 *
 * ⚠ **C'EST LA RÈGLE QUI EMPÊCHE DE FACTURER DEUX FOIS LE MÊME MANQUEMENT.** `RegleAnnulation`
 * existe depuis longtemps et calcule déjà une indemnité de dédit. Laisser les deux mécanismes
 * s'appliquer sur une prestation à arrhes ferait payer au client son absence deux fois — une fois
 * en perdant ses arrhes, une fois par la facturation. Personne ne l'aurait vu avant une réclamation.
 *
 * ⚠ **ET SI RIEN N'A ÉTÉ RETENU, LE RÉGIME DES ARRHES NE S'EST JAMAIS ENGAGÉ.** Un montant déclaré
 * mais jamais encaissé ne protège personne : on retombe alors sur la facturation ordinaire, sinon
 * une prestation mal configurée offrirait l'absence à tous ses clients.
 *
 * ── CE QUI N'EST PAS FAIT, ET QUI SE SAIT ───────────────────────────────────────────────────────
 *
 * ⚠ **LE DÉDIT DU PROFESSIONNEL N'EXISTE NULLE PART.** Sur des arrhes, l'établissement qui annule
 * doit au client le DOUBLE de ce qu'il a reçu. Aucun code ne sait faire ce remboursement, et rien
 * ne le signale à l'exploitant qui annule. Poser cette énumération sans le dire reviendrait à
 * promettre un régime qu'on n'applique qu'à moitié — celle qui arrange le vendeur.
 *
 * ⚠ Et ceci n'est pas un avis juridique : ce qui fait foi, ce sont les conditions générales de
 * l'exploitant. Le code doit refléter ce qu'elles disent.
 */
enum DepositKind: string
{
    /** Aucun versement demandé : comportement d'origine, inchangé. C'est le défaut. */
    case None = 'none';

    /** Acompte — avance sur le prix, engagement ferme, s'impute sur le solde dû. */
    case PartPayment = 'part_payment';

    /** Arrhes — faculté de dédit, perdues par le client qui ne vient pas, rien de plus réclamé. */
    case EarnestMoney = 'earnest_money';

    /**
     * Ce versement éteint-il toute réclamation ultérieure quand le client ne vient pas ?
     *
     * Vrai pour les seules arrhes : c'est exactement ce qui les distingue d'un acompte, et le seul
     * endroit du code où la distinction se lit.
     */
    public function eteintLaCreance(): bool
    {
        return $this === self::EarnestMoney;
    }

    public function libelle(): string
    {
        return match ($this) {
            self::None => 'Aucun versement',
            self::PartPayment => 'Acompte',
            self::EarnestMoney => 'Arrhes',
        };
    }
}
