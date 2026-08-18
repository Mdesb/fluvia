<?php

declare(strict_types=1);

namespace App\Boutique\Billet;

use App\Facturation\Entity\Facture;
use App\Vente\Entity\Vente;
use Dompdf\Dompdf;
use Dompdf\Options;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Environment;

/**
 * Facture PDF **best-effort**, jointe à l'e-mail de confirmation quand elle est disponible
 * (§4.8 spec-boutique.md « e-mail de confirmation envoyé avec récapitulatif, billets et facture »).
 *
 * ⚠ **Point d'intégration noté, non forcé** (cf. rapport du lot) — aucun mécanisme du dépôt n'émet
 * aujourd'hui automatiquement une `App\Facturation\Entity\Facture` à la validation d'une `Vente` en
 * ligne (le module Facturation expose seulement `POST /factures/depuis-vente`, déclenché
 * manuellement — cf. `App\Facturation\State\EmettreFactureJustificativeProcessor`, code réel, non
 * modifié ici). Ce générateur **lit seulement** (aucune écriture, aucun fichier `App\Facturation\*`
 * modifié) une `Facture` déjà émise et rattachée à la `Vente`, si elle existe ; sinon
 * `genererSiDisponible()` renvoie `null` et l'e-mail part sans pièce jointe facture (repli explicite,
 * cf. `ConfirmationCommandeMailer`).
 */
final class GenerateurPdfFacture
{
    public function __construct(
        private readonly Environment $twig,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function genererSiDisponible(Vente $vente): ?string
    {
        $facture = $this->em->getRepository(Facture::class)->findOneBy(['venteOrigine' => $vente]);
        if ($facture === null || $facture->estBrouillon()) {
            return null;
        }

        $destinataire = $facture->getDestinataire();
        $lignes = [];
        foreach ($facture->getLignes() as $ligne) {
            $lignes[] = [
                'designation' => $ligne->getDesignation(),
                'quantite' => $ligne->getQuantite(),
                'prixUnitaireHT' => $ligne->getPrixUnitaireHT(),
                'montantTTC' => $ligne->getMontantTTC(),
            ];
        }

        $html = $this->twig->render('boutique/billet/facture_pdf.html.twig', [
            'numero' => $facture->getNumero(),
            'dateEmission' => $facture->getDateEmission(),
            'destinataire' => $destinataire?->denomination(),
            'lignes' => $lignes,
            'totalHT' => $facture->getTotalHT(),
            'totalTVA' => $facture->getTotalTVA(),
            'totalTTC' => $facture->getTotalTTC(),
            'mentionAcquittee' => $facture->isMentionAcquittee(),
        ]);

        return $this->rendrePdf($html);
    }

    private function rendrePdf(string $html): string
    {
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setChroot(\sys_get_temp_dir());

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
