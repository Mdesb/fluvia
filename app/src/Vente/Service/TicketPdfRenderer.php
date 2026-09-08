<?php

declare(strict_types=1);

namespace App\Vente\Service;

use App\Platform\Pdf\PoliceDeclaree;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * LE TICKET EN PDF AU FORMAT D'UN ROULEAU — 80 mm de large, hauteur libre.
 *
 * ── POURQUOI UN PDF ET PAS DE L'ESC/POS ────────────────────────────────────────────────────────
 *
 * L'ESC/POS est la vraie sortie de production : octets bruts, coupe papier, tiroir-caisse, aucun
 * pilote. Mais il ne parle qu'aux imprimantes thermiques — envoyé à une jet d'encre ou une laser, il
 * sort en charabia. Ce PDF s'imprime partout, sert à juger la mise en page et le contenu avant
 * d'acheter du matériel, et reste utile ensuite pour l'envoi par courriel et l'archivage.
 *
 * Les deux lisent le MÊME `DocumentTicket` : c'est la seule chose qui empêche le papier et l'écran
 * de diverger.
 *
 * ── ⚠ LA POLICE SE DÉCLARE, TOUJOURS ──────────────────────────────────────────────────────────
 *
 * `PoliceDeclaree` existe parce que le cache de police de Dompdf est statique **de fonction** : sans
 * déclaration, la typographie d'un PDF dépend de ce que le worker PHP-FPM a rendu avant lui. Deux
 * clients, même produit, même jour, deux tickets différents. Son en-tête donne les mesures.
 *
 * ── ⚠ LA HAUTEUR EST UNE ESTIMATION, ET ELLE EST VOLONTAIREMENT GÉNÉREUSE ─────────────────────
 *
 * Dompdf ne sait pas dimensionner une page sur son contenu : la hauteur se donne à l'avance. Trop
 * courte, le ticket est TRONQUÉ — un document amputé qui a l'air normal. Trop longue, il reste du
 * blanc en bas, ce qui ne coûte rien sur un rouleau et se voit tout de suite sur une feuille. Entre
 * un défaut invisible et un défaut visible, on choisit le visible.
 *
 * ── ⚠ CE QUI MANQUE EST IMPRIMÉ, PAS OMIS ─────────────────────────────────────────────────────
 *
 * Deux mentions obligatoires ne sont pas encore dans le modèle : l'identité du vendeur, et le taux
 * de TVA de la plupart des produits. Les taire donnerait un ticket qui a l'air fini ; ce rendu écrit
 * donc noir sur blanc ce qu'il ne sait pas. Le premier papier sorti devient la liste de ce qui reste
 * à faire, au lieu d'un faux document rassurant.
 *
 * ⚠ **Et il n'écrit PAS « logiciel certifié NF525 ».** La mention est obligatoire pour un logiciel
 * certifié ; nous ne le sommes pas. L'imprimer serait une affirmation fausse sur un document
 * opposable — exactement ce qu'un contrôle cherche.
 */
final class TicketPdfRenderer
{
    /** 80 mm en points PostScript (72 pt par pouce, 25,4 mm par pouce). */
    private const LARGEUR_PT = 226.77;

    /** Marge haute et basse, en points — le rouleau a besoin d'un peu d'air pour la coupe. */
    private const MARGE_PT = 14.0;

    /** Hauteur estimée d'une ligne de texte à 9 pt, interligne compris. */
    private const LIGNE_PT = 12.0;

    /**
     * @param array<string, mixed> $document tel que rendu par `DocumentTicket::pour()`
     */
    public function render(array $document): string
    {
        $html = $this->html($document);

        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setChroot(\sys_get_temp_dir());

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(PoliceDeclaree::dans($html, "'Courier','DejaVu Sans Mono',monospace"), 'UTF-8');
        $dompdf->setPaper([0.0, 0.0, self::LARGEUR_PT, $this->hauteur($document)]);
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * La hauteur à réserver, en points.
     *
     * Chaque poste est compté séparément plutôt qu'avec un forfait : le jour où une section grossit,
     * c'est cette méthode qu'on corrige, et le lien avec le contenu reste lisible.
     *
     * @param array<string, mixed> $document
     */
    private function hauteur(array $document): float
    {
        $lignes = \is_array($document['lignes'] ?? null) ? $document['lignes'] : [];
        $vat = \is_array($document['vat'] ?? null) ? $document['vat'] : [];
        $ventilation = \is_array($vat['breakdown'] ?? null) ? $vat['breakdown'] : [];

        $nb = 6                          // en-tête vendeur + avertissements
            + 2                          // numéro et date
            + 2 * \count($lignes)        // libellé puis quantité/montant
            + 2                          // total
            + 1 + \count($ventilation)   // titre de ventilation + un poste par taux
            + 3;                         // duplicata, pied, air

        return self::LIGNE_PT * $nb + 2 * self::MARGE_PT;
    }

    /**
     * Le rendu, avant sa mise en PDF — **public parce que c'est lui qu'on peut prouver**.
     *
     * Un PDF sorti de Dompdf est un flux compressé : y chercher une chaîne ne prouve rien, et un
     * test qui n'assure que « ça commence par %PDF » laisserait passer un ticket dont toutes les
     * mentions ont disparu. Le HTML est l'endroit où l'on peut vérifier qu'une mention obligatoire
     * est bien écrite — et surtout qu'un avertissement ne s'imprime PAS quand il n'a pas lieu d'être.
     *
     * @param array<string, mixed> $document
     */
    public function html(array $document): string
    {
        $lignes = \is_array($document['lignes'] ?? null) ? $document['lignes'] : [];
        $vat = \is_array($document['vat'] ?? null) ? $document['vat'] : [];

        $corps = '';

        // ⚠ L'identité du vendeur est une mention obligatoire, et le modèle ne la porte pas encore.
        // On l'écrit, on ne la tait pas.
        $corps .= '<div class="bloc alerte">VENDEUR NON RENSEIGNE<br>mention obligatoire absente du modele</div>';

        if (($document['duplicata'] ?? false) === true) {
            $corps .= '<div class="bloc duplicata">DUPLICATA</div>';
        }

        $corps .= '<div class="bloc">'
            . 'Ticket ' . $this->texte($document['numero'] ?? '—') . '<br>'
            . $this->dateLisible($this->texte($document['date'] ?? ''))
            . '</div>';

        $corps .= '<div class="bloc lignes">';
        foreach ($lignes as $ligne) {
            if (!\is_array($ligne)) {
                continue;
            }
            $corps .= '<div class="art">' . $this->libelle($ligne['libelle'] ?? null) . '</div>'
                . '<div class="mnt"><span>' . $this->texte((string) ($ligne['quantite'] ?? 1)) . ' x</span>'
                . '<span>' . $this->euros($ligne['montantLigne'] ?? null) . '</span></div>';
        }
        $corps .= '</div>';

        $corps .= '<div class="bloc total"><span>TOTAL</span><span>'
            . $this->euros($document['total'] ?? null) . '</span></div>';

        $corps .= $this->blocTva($vat);

        return $this->gabarit($corps);
    }

    /** @param array<string, mixed> $vat */
    private function blocTva(array $vat): string
    {
        $ventilation = \is_array($vat['breakdown'] ?? null) ? $vat['breakdown'] : [];

        $html = '<div class="bloc tva"><div class="titre">TVA</div>';

        foreach ($ventilation as $groupe) {
            if (!\is_array($groupe)) {
                continue;
            }
            $html .= '<div class="mnt"><span>' . $this->texte((string) ($groupe['rate'] ?? '')) . '% sur '
                . $this->euros($groupe['netAmount'] ?? null) . '</span>'
                . '<span>' . $this->euros($groupe['vatAmount'] ?? null) . '</span></div>';
        }

        // ⚠ Le cas fréquent aujourd'hui, et celui qu'il ne faut surtout pas taire : presque aucun
        // produit ne porte de taux, donc la ventilation est vide. Un bloc TVA vide se lirait comme
        // « pas de TVA », ce qui est faux — il y en a une, on ne sait pas laquelle.
        if (($vat['complete'] ?? true) !== true) {
            $sans = \is_array($vat['withoutRate'] ?? null) ? $vat['withoutRate'] : [];
            $html .= '<div class="alerte">VENTILATION INCOMPLETE<br>'
                . $this->texte((string) ($sans['lines'] ?? 0)) . ' ligne(s) sans taux, '
                . $this->euros($sans['grossAmount'] ?? null) . ' non ventiles</div>';
        }

        return $html . '</div>';
    }

    /**
     * Le libellé du produit, qui est un TABLEAU traduisible et non une chaîne.
     *
     * ⚠ **La langue devrait être celle de l'établissement, et ce champ n'existe pas encore.**
     * `Etablissement` ne porte que `pays`, et je refuse d'en déduire la langue : la Belgique en a
     * trois, la Suisse quatre — c'est la forme exacte d'un défaut déjà rencontré ici, une règle
     * verrouillée sur un pays qui passe tous les tests et rend le produit invendable ailleurs.
     *
     * En attendant `Etablissement::$langue` (demandé à claude-A, dont c'est le périmètre), on prend
     * le français s'il existe, sinon la seule traduction disponible. Aucune invention : quand il n'y
     * en a aucune, on écrit un tiret plutôt qu'un nom de remplacement.
     */
    private function libelle(mixed $libelle): string
    {
        if (\is_string($libelle)) {
            return $this->texte($libelle);
        }
        if (!\is_array($libelle) || $libelle === []) {
            return '—';
        }
        if (isset($libelle['fr']) && \is_string($libelle['fr'])) {
            return $this->texte($libelle['fr']);
        }

        $premiere = reset($libelle);

        return \is_string($premiere) ? $this->texte($premiere) : '—';
    }

    private function euros(mixed $montant): string
    {
        if (!\is_string($montant) && !\is_int($montant) && !\is_float($montant)) {
            return '—';
        }

        return $this->texte(number_format((float) $montant, 2, ',', ' ')) . ' EUR';
    }

    /** `2026-09-08T10:22:01+02:00` se lit mal sur un ticket ; on rend le jour et l'heure. */
    private function dateLisible(string $iso): string
    {
        $date = date_create_immutable($iso);

        return $date === false ? $this->texte($iso) : $date->format('d/m/Y H:i');
    }

    private function texte(mixed $valeur): string
    {
        return htmlspecialchars(\is_scalar($valeur) ? (string) $valeur : '', \ENT_QUOTES, 'UTF-8');
    }

    private function gabarit(string $corps): string
    {
        return <<<HTML
            <html><head><meta charset="utf-8"><style>
              @page { margin: 0; }
              body { margin: 4mm; font-size: 9pt; line-height: 1.25; }
              .bloc { margin-bottom: 5pt; }
              .lignes { border-top: 1px dashed #000; border-bottom: 1px dashed #000; padding: 3pt 0; }
              .art { font-weight: bold; }
              .mnt { display: block; overflow: hidden; }
              .mnt span:first-child { float: left; }
              .mnt span:last-child { float: right; }
              .total { font-size: 11pt; font-weight: bold; overflow: hidden; }
              .total span:first-child { float: left; }
              .total span:last-child { float: right; }
              .tva .titre { font-weight: bold; }
              .duplicata { text-align: center; font-weight: bold; font-size: 12pt;
                           border: 1px solid #000; padding: 2pt; }
              .alerte { border: 1px dashed #000; padding: 2pt; text-align: center; font-size: 8pt; }
            </style></head><body>{$corps}</body></html>
            HTML;
    }
}
