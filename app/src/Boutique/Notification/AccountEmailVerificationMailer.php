<?php

declare(strict_types=1);

namespace App\Boutique\Notification;

use App\Securite\Entity\EmailVerificationToken;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * Le courriel qui porte la preuve d'adresse — et le seul endroit ou le jeton existe en clair.
 *
 * ⚠ IL REFUSE D'ENVOYER SANS URL DE BASE, AU LIEU D'ENVOYER UN LIEN MORT. `BOUTIQUE_BASE_URL` vide
 * signifie qu'on ne sait pas ou vit la boutique : un courriel partirait quand meme, avec un lien
 * vers nulle part, et le client croirait le produit casse. Echouer en silence ET rassurer est la
 * pire des deux options ; ici on n'envoie rien et l'appelant le sait.
 *
 * ⚠ ON NE DEDUIT PAS L'HOTE DE LA REQUETE. `getSchemeAndHttpHost()` aurait evite cette variable --
 * et aurait permis a qui sait forger un en-tete `Host` de faire partir, vers l'adresse d'un tiers,
 * un lien de verification pointant chez lui. Le jeton part a la victime, le lien mene a l'attaquant :
 * c'est la faille classique des tunnels de reinitialisation. Une valeur configuree, jamais devinee.
 *
 * ⚠ ET `VITRINE_BASE_URL` N'EST PAS CELLE-LA. Elle designe le site vitrine (elle vaut
 * `http://localhost:5174` par defaut) ; l'emprunter enverrait les clients sur un autre produit.
 */
final class AccountEmailVerificationMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        #[Autowire(env: 'BOUTIQUE_BASE_URL')] private readonly string $baseUrl = '',
    ) {
    }

    /**
     * @param string $jetonClair le jeton NON hache — il ne doit exister qu'ici et dans le courriel
     *
     * @return bool false quand aucune URL de base n'est configuree : rien n'est parti
     */
    public function envoyer(EmailVerificationToken $jeton, string $jetonClair): bool
    {
        $base = rtrim($this->baseUrl, '/');
        if ($base === '') {
            return false;
        }

        $lien = $base.'/verifier-email?jeton='.rawurlencode($jetonClair);

        $html = $this->twig->render('boutique/email/verification_email.html.twig', [
            'lien' => $lien,
            'heures' => 48,
        ]);

        $email = (new Email())
            ->to($jeton->getAdresse())
            ->from('no-reply@itcotation.com')
            ->subject('Confirmez votre adresse e-mail')
            ->text(
                "Bonjour,\n\nConfirmez votre adresse pour activer votre compte et retrouver vos "
                ."commandes passees sans compte :\n".$lien."\n\nCe lien est valable 48 heures. "
                ."Si vous n'avez pas cree de compte, ignorez ce message."
            )
            ->html($html);

        $this->mailer->send($email);

        return true;
    }
}
