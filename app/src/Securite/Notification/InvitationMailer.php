<?php

declare(strict_types=1);

namespace App\Securite\Notification;

use App\Securite\Entity\Utilisateur;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Envoi de l'e-mail d'invitation (RG-M8-01, CA-1) : lien front porteur du jeton d'invitation en
 * clair (jamais renvoyé par l'API — seul le hash est persisté). Transport `null://null` par
 * défaut en dev/test (`MAILER_DSN`) : aucun envoi réel, juste vérification de l'émission.
 */
final class InvitationMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        #[Autowire(env: 'FRONT_BASE_URL')] private readonly string $frontBaseUrl,
    ) {
    }

    public function envoyer(Utilisateur $utilisateur, string $jetonClair): void
    {
        $lien = sprintf('%s/activation?jeton=%s', rtrim($this->frontBaseUrl, '/'), $jetonClair);

        $email = (new Email())
            ->to($utilisateur->getEmail())
            ->from('no-reply@itcotation.com')
            ->subject('Activation de votre compte')
            ->text(sprintf(
                "Bonjour %s,\n\nVotre compte a été créé. Activez-le en définissant votre mot de passe : %s\n\nCe lien expire dans 72 heures.",
                $utilisateur->getNom(),
                $lien
            ));

        $this->mailer->send($email);
    }
}
