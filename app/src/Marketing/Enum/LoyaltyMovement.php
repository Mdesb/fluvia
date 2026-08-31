<?php

declare(strict_types=1);

namespace App\Marketing\Enum;

/**
 * Les seuls mouvements de points qui se STOCKENT.
 *
 * Les points **gagnés** n'y figurent pas, et c'est le cœur du module : ils se recalculent depuis
 * les ventes validées, à chaque lecture. Un compteur de points gagnés se désynchroniserait au
 * premier avoir, à la première vente annulée, au premier changement de règle — et un solde faux se
 * lit exactement comme un solde juste.
 *
 * > **Ce qui se déduit se déduit ; ce qui se décide se stocke.**
 *
 * Une dépense et un geste commercial sont des décisions humaines : rien ne permettrait de les
 * retrouver après coup.
 */
enum LoyaltyMovement: string
{
    /** Le client échange des points contre une contrepartie. Toujours négatif. */
    case Depense = 'depense';

    /**
     * Un geste de l'exploitant : rattrapage d'incident, bienvenue, correction d'erreur.
     *
     * Positif ou négatif, mais **jamais sans motif** : un ajustement anonyme est indéfendable
     * devant le client qui demande pourquoi son solde a bougé.
     */
    case Ajustement = 'ajustement';

    public function label(): string
    {
        return match ($this) {
            self::Depense => 'Dépense',
            self::Ajustement => 'Ajustement',
        };
    }
}
