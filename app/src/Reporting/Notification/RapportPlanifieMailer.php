<?php

declare(strict_types=1);

namespace App\Reporting\Notification;

use App\Reporting\Entity\Export;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Envoi de l'e-mail d'un `RapportPlanifie` généré (RG-M7-06, §2.8 plan-reporting.md), pièce jointe
 * = fichier produit par `GenerateurExportInterface` (même patron que
 * `App\Securite\Notification\ReinitialisationMailer`, transport `null://null` par défaut en test).
 */
final class RapportPlanifieMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
    ) {
    }

    public function envoyer(Export $export, string $destinataireEmail, string $nomRapport, string $contenu, string $nomFichier): void
    {
        $email = (new Email())
            ->to($destinataireEmail)
            ->from('no-reply@itcotation.com')
            ->subject(sprintf('Rapport reporting — %s', $nomRapport))
            ->text(sprintf(
                "Bonjour,\n\nVeuillez trouver ci-joint le rapport « %s », restreint à votre périmètre (RG-M7-07).\n",
                $nomRapport
            ))
            ->attach($contenu, $nomFichier);

        $this->mailer->send($email);
    }
}
