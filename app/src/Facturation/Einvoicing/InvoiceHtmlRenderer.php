<?php

declare(strict_types=1);

namespace App\Facturation\Einvoicing;

use App\Facturation\Entity\Facture;
use App\Facturation\Entity\LigneFacture;
use Twig\Environment;

/**
 * LE RENDU VISUEL D'UNE FACTURE — la moitié que l'humain lit.
 *
 * Factur-X est un couple : un PDF lisible par une personne, et le XML qu'une machine dépouille. Le
 * XML a son sérialiseur ; il manquait un point d'entrée pour la première moitié qui parte d'une
 * `Facture` plutôt que d'une `Vente`.
 *
 * ── ⚠ POURQUOI CETTE CLASSE PLUTÔT QU'UNE RECOPIE ──────────────────────────────────────────────
 *
 * `Boutique\Billet\GenerateurPdfFacture` construisait déjà ce contexte, mais à partir d'une `Vente`.
 * Or une facture directe n'a pas de vente : la commande d'émission ne pouvait pas l'appeler.
 *
 * Recopier les dix lignes de correspondance aurait créé deux vérités sur le même gabarit — et le
 * jour où l'une gagne une colonne, l'autre l'ignore en silence. C'est exactement la forme des
 * défauts que ce dépôt collectionne. Le générateur de `Boutique` appelle donc cette classe.
 *
 * ⚠ ELLE NE SAIT RIEN DE FACTUR-X, ET C'EST VOULU. Elle rend du HTML. C'est `FacturXAssembler` qui
 * décide d'en faire un PDF/A-3 avec un XML dedans. Mélanger les deux ferait dépendre le rendu
 * ordinaire d'une contrainte d'archivage qui ne le concerne pas.
 */
final class InvoiceHtmlRenderer
{
    private const GABARIT = 'boutique/billet/facture_pdf.html.twig';

    public function __construct(
        private readonly Environment $twig,
    ) {
    }

    public function render(Facture $facture): string
    {
        $lignes = [];
        foreach ($facture->getLignes() as $ligne) {
            \assert($ligne instanceof LigneFacture);
            $lignes[] = [
                'designation' => $ligne->getDesignation(),
                'quantite' => $ligne->getQuantite(),
                'prixUnitaireHT' => $ligne->getPrixUnitaireHT(),
                'montantTTC' => $ligne->getMontantTTC(),
            ];
        }

        return $this->twig->render(self::GABARIT, [
            'numero' => $facture->getNumero(),
            'dateEmission' => $facture->getDateEmission(),
            'destinataire' => $facture->getDestinataire()?->denomination(),
            'lignes' => $lignes,
            'totalHT' => $facture->getTotalHT(),
            'totalTVA' => $facture->getTotalTVA(),
            'totalTTC' => $facture->getTotalTTC(),
            'mentionAcquittee' => $facture->isMentionAcquittee(),
        ]);
    }
}
