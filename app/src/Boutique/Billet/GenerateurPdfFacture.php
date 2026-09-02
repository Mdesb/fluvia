<?php

declare(strict_types=1);

namespace App\Boutique\Billet;

use App\Facturation\Entity\Facture;
use App\Vente\Entity\Vente;
use Dompdf\Dompdf;
use Dompdf\Options;
use Doctrine\ORM\EntityManagerInterface;
use App\Facturation\Einvoicing\InvoiceHtmlRenderer;

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
        private readonly InvoiceHtmlRenderer $renduHtml,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function genererSiDisponible(Vente $vente): ?string
    {
        $facture = $this->em->getRepository(Facture::class)->findOneBy(['venteOrigine' => $vente]);
        if ($facture === null || $facture->estBrouillon()) {
            return null;
        }

        // ⚠ LA CORRESPONDANCE GABARIT <- FACTURE VIT DESORMAIS DANS `InvoiceHtmlRenderer`.
        //
        // Elle etait ici, et la commande d'emission europeenne ne pouvait pas l'appeler : ce
        // generateur part d'une `Vente`, or une facture DIRECTE n'en a pas. La recopier aurait cree
        // deux verites sur le meme gabarit — et le jour ou l'une gagne une colonne, l'autre l'ignore
        // en silence.
        return $this->rendrePdf($this->renduHtml->render($facture));
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
