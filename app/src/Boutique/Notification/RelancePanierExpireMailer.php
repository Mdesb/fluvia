<?php

declare(strict_types=1);

namespace App\Boutique\Notification;

use App\Boutique\Entity\PanierEnLigne;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Relance e-mail/push d'un panier expiré (RG-M3-16, CA-3, décision actée « Abandon de panier ») —
 * n'envoie que si un contact est connu (§8 spec, cas limite « panier expiré avant identification »).
 */
final class RelancePanierExpireMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
    ) {
    }

    public function envoyerSiContactConnu(PanierEnLigne $panier): bool
    {
        $destinataire = $panier->getCompteClient()?->getUtilisateur()?->getEmail() ?? $panier->getContactConnu();
        if ($destinataire === null || $destinataire === '') {
            return false;
        }

        $email = (new Email())
            ->to($destinataire)
            ->from('no-reply@itcotation.com')
            ->subject('Votre panier a expiré')
            ->text('Bonjour, votre panier a expiré faute de paiement dans le délai imparti. Le contenu de votre panier est toujours accessible pour un nouvel achat.');

        $this->mailer->send($email);

        return true;
    }
}
