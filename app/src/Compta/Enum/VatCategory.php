<?php

declare(strict_types=1);

namespace App\Compta\Enum;

/**
 * BT-151 — LA CATÉGORIE DE TVA, QUI N'EST PAS LE TAUX.
 *
 * EN 16931 exige, sur chaque ligne, une **catégorie** en plus du taux. Les codes viennent de la
 * liste UNTDID 5305 ; en inventer un ferait refuser la facture.
 *
 * ── ⚠ POURQUOI ON NE LA DÉDUIT PAS DU TAUX, ET LA MESURE QUI LE PROUVE ─────────────────────────
 *
 * « Taux à 0 donc catégorie Z » est le raccourci naturel. Il est faux. Mesure du 02/09 sur la
 * préproduction : **six taux à 0 %**, tous libellés « Hors champ (opération non commerciale) ».
 * Hors champ, c'est `O` — pas `Z`, pas `E`. Les trois se ressemblent sur une facture, se
 * distinguent au contrôle fiscal, et n'appellent pas les mêmes mentions obligatoires.
 *
 * Un taux **positif**, lui, ne pose pas la question : réduit ou normal, il est `S`. C'est le taux
 * qui distingue 5,5 % de 20 %, pas la catégorie.
 *
 * ── LA CATÉGORIE APPARTIENT AU TAUX, PAS À LA LIGNE ─────────────────────────────────────────────
 *
 * « TVA 0 % exonérée » et « TVA 0 % hors champ » ne sont pas le même taux avec deux étiquettes : ce
 * sont deux taux distincts qu'un exploitant crée séparément. Porter la catégorie sur la ligne
 * obligerait à la ressaisir à chaque vente et permettrait deux réponses différentes pour un même
 * taux — c'est-à-dire une facture incohérente qu'aucun contrôle ne verrait.
 */
enum VatCategory: string
{
    /** `S` — taux standard. Couvre le taux normal ET les taux réduits : c'est le taux qui les sépare. */
    case Standard = 'S';

    /** `Z` — taux zéro. La TVA s'applique, à 0 %. Rare en France, courant à l'export intra-UE de biens. */
    case ZeroRated = 'Z';

    /** `E` — exonéré. L'opération entre dans le champ de la TVA mais en est exonérée (art. 261 CGI). */
    case Exempt = 'E';

    /** `AE` — autoliquidation : c'est le preneur qui déclare la TVA. Sous-traitance BTP, achats intra-UE. */
    case ReverseCharge = 'AE';

    /** `K` — livraison intracommunautaire exonérée vers un assujetti d'un autre État membre. */
    case IntraCommunity = 'K';

    /** `G` — exportation hors Union européenne, exonérée. */
    case Export = 'G';

    /**
     * `O` — hors champ. L'opération n'entre pas dans le champ d'application de la TVA.
     *
     * ⚠ C'est le cas des six taux à 0 % mesurés en préproduction, libellés « opération non
     * commerciale » : une régie municipale qui encaisse une participation d'usager n'est pas
     * exonérée, elle est hors champ. La différence n'est pas de vocabulaire.
     */
    case OutOfScope = 'O';

    /**
     * Le libellé destiné à l'humain qui choisit dans une liste.
     *
     * ⚠ Le code, lui, ne se traduit jamais : `AE` part tel quel dans le fichier européen.
     */
    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Taux normal ou réduit',
            self::ZeroRated => 'Taux zéro',
            self::Exempt => 'Exonéré',
            self::ReverseCharge => 'Autoliquidation',
            self::IntraCommunity => 'Livraison intracommunautaire',
            self::Export => 'Exportation hors UE',
            self::OutOfScope => 'Hors champ de la TVA',
        };
    }

    /**
     * La catégorie qu'un taux **positif** porte nécessairement.
     *
     * ⚠ IL N'Y A PAS D'ÉQUIVALENT POUR UN TAUX À ZÉRO, et c'est délibéré. Zéro peut être `Z`, `E`,
     * `AE`, `K`, `G` ou `O` : seul un humain qui sait pourquoi le taux est nul peut trancher. Une
     * méthode qui « devinerait » rendrait une réponse plausible et fausse, et personne ne la
     * relirait.
     */
    public static function forPositiveRate(): self
    {
        return self::Standard;
    }
}
