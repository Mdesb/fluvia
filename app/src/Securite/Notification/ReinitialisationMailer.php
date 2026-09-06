<?php

declare(strict_types=1);

namespace App\Securite\Notification;

use App\Securite\Entity\Utilisateur;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Envoi de l'e-mail de réinitialisation de mot de passe (US-L0-03 différée, CA-6). Transport
 * `null://null` par défaut en dev/test.
 */
final class ReinitialisationMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        #[Autowire(env: 'FRONT_BASE_URL')] private readonly string $frontBaseUrl,
    ) {
    }

    public function envoyer(Utilisateur $utilisateur, string $jetonClair): void
    {
        $lien = sprintf('%s/nouveau-mot-de-passe?jeton=%s', rtrim($this->frontBaseUrl, '/'), $jetonClair);

        $email = (new Email())
            ->to($utilisateur->getEmail())
            ->from('no-reply@itcotation.com')
            ->subject('Réinitialisation de votre mot de passe')
            ->text(sprintf(
                "Bonjour,\n\nUne demande de réinitialisation de mot de passe a été effectuée pour ce compte : %s\n\nCe lien expire dans 1 heure. Si vous n'êtes pas à l'origine de cette demande, ignorez cet e-mail.",
                $lien
            ));

        $this->mailer->send($email);
    }
}
