<?php

declare(strict_types=1);

namespace App\Boutique\Notification;

use App\I18n\Locales;
use App\Boutique\Billet\GenerateurPdfBillet;
use App\Boutique\Billet\GenerateurPdfFacture;
use App\Boutique\Entity\PanierEnLigne;
use App\Vente\Entity\Vente;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * E-mail de confirmation de commande (US-L8-08, RG-M3-04/08/14, CA-11) : corps HTML (gabarit Twig,
 * `MailerInterface` employé directement, patron `App\Securite\Notification\InvitationMailer`) avec le
 * **billet PDF** (`GenerateurPdfBillet`, QR image inclus) en pièce jointe, et la **facture PDF** si
 * elle est déjà disponible (`GenerateurPdfFacture::genererSiDisponible()`, best-effort — cf. rapport du
 * lot : aucun mécanisme du dépôt n'émet encore automatiquement de facture pour une vente en ligne).
 * Transport `null://null` en dev/test : aucun envoi réel, seul le contenu (HTML + pièces jointes) du
 * `RawMessage` est vérifié (collecteur `Symfony\Component\Mailer\Event\MessageEvent` en test).
 */
final class ConfirmationCommandeMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly GenerateurPdfBillet $generateurPdfBillet,
        private readonly GenerateurPdfFacture $generateurPdfFacture,
    ) {
    }

    public function envoyer(Vente $vente, PanierEnLigne $panier): void
    {
        $destinataire = $panier->getCompteClient()?->getUtilisateur()?->getEmail() ?? $panier->getContactConnu();
        if ($destinataire === null || $destinataire === '') {
            return;
        }

        $nbBillets = $vente->getSupports()->count();

        $pdfFacture = $this->generateurPdfFacture->genererSiDisponible($vente);

        $html = $this->twig->render('boutique/email/confirmation.html.twig', [
            'locale' => Locales::ofEstablishment($vente->getEtablissement()),
            'etablissementNom' => $vente->getEtablissement()?->getNom() ?? '',
            'numeroCommande' => $vente->getNumero(),
            'total' => $vente->getTotal(),
            'nbBillets' => $nbBillets,
            'factureJointe' => $pdfFacture !== null,
        ]);

        $email = (new Email())
            ->to($destinataire)
            ->from('no-reply@itcotation.com')
            ->subject('Confirmation de votre commande n°' . $vente->getNumero())
            ->text(sprintf(
                "Bonjour,\n\nVotre commande n°%s (%s €) est confirmée. Vos billets sont disponibles immédiatement (pièce jointe et dans votre espace client).\n\nMerci de votre confiance.",
                $vente->getNumero(),
                $vente->getTotal(),
            ))
            ->html($html);

        if ($nbBillets > 0) {
            $email->attach(
                $this->generateurPdfBillet->genererPourVente($vente),
                'billets-' . $vente->getNumero() . '.pdf',
                'application/pdf',
            );
        }

        if ($pdfFacture !== null) {
            $email->attach($pdfFacture, 'facture-' . $vente->getNumero() . '.pdf', 'application/pdf');
        }

        $this->mailer->send($email);
    }
}
