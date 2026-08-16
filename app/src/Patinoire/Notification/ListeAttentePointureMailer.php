<?php

declare(strict_types=1);

namespace App\Patinoire\Notification;

use App\Patinoire\Entity\ListeAttentePointure;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Notification d'un inscrit en liste d'attente pointure dès qu'une unité redevient disponible
 * (décision actée « pointure en rupture », §4.5, plan §0 point 8). Canal **e-mail uniquement** dans ce
 * lot (⚠ hypothèse — SMS/app hors périmètre, Risque n°8 du plan), même patron que
 * `App\Securite\Notification\InvitationMailer`. Transport `null://null` par défaut en dev/test :
 * aucun envoi réel, juste vérification de l'émission.
 */
final class ListeAttentePointureMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
    ) {
    }

    public function notifier(ListeAttentePointure $inscription): void
    {
        $email = $inscription->getBeneficiaire()?->getClient()?->getEmail();
        if ($email === null || $email === '') {
            return;
        }

        $pointure = $inscription->getParcPatins()?->getPointure();

        $message = (new Email())
            ->to($email)
            ->from('no-reply@itcotation.com')
            ->subject('Une paire de patins est disponible')
            ->text(sprintf(
                "Bonjour,\n\nUne paire de patins en pointure %s est de nouveau disponible. "
                . "Merci de vous présenter au comptoir pour la récupérer.",
                $pointure !== null ? (string) $pointure : '?',
            ));

        $this->mailer->send($message);
    }
}
