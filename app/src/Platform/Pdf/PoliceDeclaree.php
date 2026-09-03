<?php

declare(strict_types=1);

namespace App\Platform\Pdf;

/**
 * Déclare une police dans le HTML avant de le confier à Dompdf.
 *
 * ⚠ SANS ÇA, LA POLICE D'UN PDF DÉPEND DE CE QUE LE PROCESSUS A RENDU AVANT LUI.
 *
 * `Dompdf\FontMetrics::getFont()` porte un `static $cache` **de fonction** : il vit dans le
 * processus, pas dans l'instance. Et lorsque la famille demandée est `null`, la clé de cache devient
 * la constante `0` — qui **n'inclut pas la police par défaut de l'instance** :
 *
 *     public function getFont($familyRaw, $subtypeRaw = "normal")
 *     {
 *         static $cache = [];
 *         if (!$familyRaw) { $familyRaw = $familyRaw === null ? 0 : $this->options->getDefaultFont(); }
 *         if (isset($cache[$familyRaw][$subtypeRaw])) { return $cache[$familyRaw][$subtypeRaw]; }
 *
 * Le premier rendu du processus remplit donc cette case, et tous les suivants la relisent — quel
 * que soit leur propre `setDefaultFont`. `Css\Style::get_font_family()` n'atteint cette clé
 * qu'après avoir épuisé les familles DÉCLARÉES : déclarer la police court-circuite le partage.
 *
 * ── CE QUE ÇA COÛTAIT, MESURÉ ───────────────────────────────────────────────────────────────────
 *
 * Dans les deux sens, et la seconde moitié n'est pas un défaut de test :
 *
 *     Factur-X seul                          9 286 octets
 *     Factur-X après un billet               « A fully embeddable font must be used when
 *                                              generating a document in PDF/A mode »
 *     billet seul                            1 148 octets, Times
 *     billet après une facture Factur-X      5 973 octets, DejaVu Sans
 *
 * Le même billet, cinq fois plus lourd et dans une autre typographie, selon ce que le worker
 * PHP-FPM avait rendu auparavant. Deux clients, même produit, même jour, deux billets différents.
 *
 * La règle porte sur `html` : plus basse spécificité possible, et `font-family` est héritée. Un
 * gabarit qui déclare la sienne la garde ; s'il en demande une non embarquable alors que PDF/A est
 * exigé, la même exception remonte — et c'est bien le gabarit qu'il faut corriger.
 */
final class PoliceDeclaree
{
    /** Embarquable, couvre le latin étendu — obligatoire en PDF/A, où les polices de base sont refusées. */
    public const DEJAVU = "'DejaVu Sans',sans-serif";

    /**
     * La police de base du PDF (Times), que Dompdf choisit déjà quand rien ne le perturbe.
     *
     * On l'épingle pour figer l'apparence ACTUELLE des billets, pas pour la changer : mesuré, un
     * billet rendu ainsi fait 1 148 octets en Times, identique au cas isolé. Sans elle, le même
     * billet fait 5 973 octets en DejaVu dès qu'une facture Factur-X est passée avant.
     */
    public const BASE = 'serif';

    private function __construct()
    {
    }

    public static function dans(string $html, string $famille): string
    {
        // ⚠ La famille finit dans une feuille de style : une accolade y ouvrirait une autre règle.
        // Les appelants passent les constantes ci-dessus ; cette garde existe pour que ça reste vrai.
        if (1 !== preg_match("/^[A-Za-z0-9 ,'\\-]+$/", $famille)) {
            throw new \InvalidArgumentException(sprintf('Famille de police non déclarable : « %s ».', $famille));
        }

        $style = sprintf('<style>html{font-family:%s;}</style>', $famille);

        // ⚠ ON REÇOIT AUSSI BIEN UN FRAGMENT QU'UN DOCUMENT. Préposer un `<style>` devant un
        // `<!DOCTYPE>` ferait basculer un vrai document en quirks mode ; on insère donc à
        // l'intérieur dès qu'il y a une structure où le faire, et on ne prépose qu'à défaut.
        foreach (['/<head\b[^>]*>/i', '/<body\b[^>]*>/i', '/<html\b[^>]*>/i'] as $motif) {
            if (1 === preg_match($motif, $html, $trouve, \PREG_OFFSET_CAPTURE)) {
                $apres = $trouve[0][1] + \strlen($trouve[0][0]);

                return substr($html, 0, $apres) . $style . substr($html, $apres);
            }
        }

        return $style . $html;
    }
}
