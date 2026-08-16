<?php

declare(strict_types=1);

namespace App\Boutique\Notification;

use App\Boutique\Entity\PanierEnLigne;
use App\Vente\Entity\Vente;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * E-mail de confirmation de commande (US-L8-08, RG-M3-04/14, CA-11) : `MailerInterface` employé
 * directement, patron `App\Securite\Notification\InvitationMailer` (code réel). Transport
 * `null://null` en dev/test : aucun envoi réel.
 */
final class ConfirmationCommandeMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
    ) {
    }

    public function envoyer(Vente $vente, PanierEnLigne $panier): void
    {
        $destinataire = $panier->getCompteClient()?->getUtilisateur()?->getEmail() ?? $panier->getContactConnu();
        if ($destinataire === null || $destinataire === '') {
            return;
        }

        $email = (new Email())
            ->to($destinataire)
            ->from('no-reply@itcotation.com')
            ->subject('Confirmation de votre commande n°' . $vente->getNumero())
            ->text(sprintf(
                "Bonjour,\n\nVotre commande n°%s (%s €) est confirmée. Vos billets sont disponibles immédiatement dans votre espace client.\n\nMerci de votre confiance.",
                $vente->getNumero(),
                $vente->getTotal(),
            ));

        $this->mailer->send($email);
    }
}
