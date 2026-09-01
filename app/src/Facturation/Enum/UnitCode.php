<?php

declare(strict_types=1);

namespace App\Facturation\Enum;

/**
 * BT-130 — L'UNITÉ DE MESURE D'UNE LIGNE DE FACTURE.
 *
 * EN 16931 exige que chaque ligne dise **de quoi** sa quantité est le compte. Aujourd'hui
 * `LigneFacture::$quantite` est un entier nu : « 3 » ne dit pas trois heures, trois entrées ou trois
 * mois. Mesuré le 02/09 par `facturation:einvoicing:etat` : c'est, avec la devise, l'un des deux
 * seuls termes obligatoires qui n'avaient **aucun champ** dans le modèle.
 *
 * ── LES CODES VIENNENT DE LA RECOMMANDATION 20 DE L'UN/ECE, PAS DE NOUS ─────────────────────────
 *
 * La norme impose cette liste : un code inventé serait refusé par tout validateur. `C62` y désigne
 * « one » — l'unité sans dimension. C'est le code qu'une billetterie utilise pour une entrée, un
 * article, une prestation forfaitaire ; c'est aussi la valeur par défaut la plus honnête pour les
 * lignes existantes, dont la quantité était déjà un compte sans unité.
 *
 * ⚠ **ON NE MET QUE CE QU'ON SAIT FACTURER.** La recommandation 20 compte plus de six cents codes.
 * En ajouter au cas où produirait une liste que personne ne sait remplir, et un champ à choix
 * multiples dont quatre-vingt-dix-neuf pour cent des options ne veulent rien dire pour une piscine
 * municipale. Chaque entrée ci-dessous correspond à quelque chose que le produit vend déjà.
 */
enum UnitCode: string
{
    /**
     * `C62` — « one ». L'unité sans dimension : une entrée, un article, une prestation.
     *
     * ⚠ C'est le DÉFAUT, et c'est un choix de fond. Une ligne dont la quantité valait « 3 » sans
     * autre indication comptait déjà trois *choses*. Écrire `C62` explicite ce qui était déjà vrai ;
     * ça n'invente rien (D66-ter).
     */
    case Piece = 'C62';

    /** `HUR` — l'heure. Location de créneau, cours particulier, mise à disposition d'un espace. */
    case Hour = 'HUR';

    /** `DAY` — le jour. Séjour, hébergement, location à la journée. */
    case Day = 'DAY';

    /** `MON` — le mois. Abonnement mensuel, mensualisation d'un engagement. */
    case Month = 'MON';

    /** `ANN` — l'année. Abonnement annuel, cotisation. */
    case Year = 'ANN';

    /**
     * Le libellé affiché, au singulier — l'écran accorde lui-même selon la quantité.
     *
     * ⚠ Le code, lui, ne se traduit JAMAIS : `HUR` part tel quel dans le fichier européen. Ce
     * libellé ne sert qu'à l'humain qui choisit dans une liste.
     */
    public function label(): string
    {
        return match ($this) {
            self::Piece => 'unité',
            self::Hour => 'heure',
            self::Day => 'jour',
            self::Month => 'mois',
            self::Year => 'année',
        };
    }
}
