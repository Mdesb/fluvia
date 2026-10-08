<?php

declare(strict_types=1);

namespace App\Reservation\Notification;

use App\I18n\Locales;
use App\Reservation\Entity\Reservation;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * Rappel d'un rendez-vous à venir — même patron que `RelancePanierExpireMailer` :
 * **n'envoie que si un contact est connu**, et le dit en rendant `false`.
 *
 * ⚠ LE RAPPEL EST LE PREMIER LEVIER CONTRE LA NON-PRÉSENTATION, et son absence était le seul manque
 * bloquant du module pour un métier de rendez-vous : sans lui, on facture l'absence au lieu de
 * l'éviter. Le port de notification existant ne portait que deux événements (promotion de liste
 * d'attente, arbitrage de récurrence) et sa seule implémentation journalisait.
 *
 * ⚠ LE CONTACT SE LIT SUR LE CLIENT, PAS SUR LE BÉNÉFICIAIRE. `Beneficiaire` ne porte aucune adresse
 * — c'est `Client` qui en porte une. Un bénéficiaire sans client rattaché (un enfant inscrit sans
 * compte, par exemple) n'a donc pas d'adresse, et ce cas est NORMAL : on ne le journalise pas comme
 * une erreur, on rend `false` et l'appelant n'estampille rien.
 */
final class AppointmentReminderMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
    ) {
    }

    /**
     * @return bool `true` si un message est parti — donc si le rendez-vous peut être estampillé
     *              comme rappelé. `false` quand aucun contact n'est connu : ce n'est pas un échec,
     *              et réessayer au cycle suivant ne servirait à rien.
     */
    public function envoyerSiContactConnu(Reservation $reservation): bool
    {
        $destinataire = $reservation->getOrganisateur()?->getClient()?->getEmail();
        if ($destinataire === null || trim($destinataire) === '') {
            return false;
        }

        $creneau = $reservation->getCreneau();
        if ($creneau === null) {
            return false;
        }

        $activite = $creneau->getActivite();
        $debut = $creneau->getDebut();

        // ⚠ LE LIBELLÉ AFFICHÉ EST CELUI DE LA PRESTATION, JAMAIS CELUI DU PRODUIT. Le produit porte
        // un nom de catalogue comptable (« PRD-BOU-TIMED ») qui ne dit rien au client.
        $prestation = $activite?->getLibelle() ?? 'votre rendez-vous';
        $lieu = $creneau->getRessource()?->getLibelle();

        $texte = sprintf(
            "Bonjour,\n\nNous vous rappelons votre rendez-vous : %s, le %s à %s%s.\n\n"
            . "Si vous ne pouvez pas venir, prévenez-nous au plus tôt : cela libère la place pour "
            . "quelqu'un d'autre.\n",
            $prestation,
            $debut->format('d/m/Y'),
            $debut->format('H\hi'),
            $lieu === null ? '' : ' (' . $lieu . ')',
        );

        $html = $this->twig->render('reservation/email/appointment_reminder.html.twig', [
            'locale' => Locales::ofEstablishment($reservation->getEtablissement()),
            'prestation' => $prestation,
            'debut' => $debut,
            'lieu' => $lieu,
            'dureeMinutes' => $activite?->getDureeMinutes(),
        ]);

        $email = (new Email())
            ->to($destinataire)
            ->from('no-reply@itcotation.com')
            ->subject(sprintf('Rappel : %s le %s à %s', $prestation, $debut->format('d/m'), $debut->format('H\hi')))
            ->text($texte)
            ->html($html);

        $this->mailer->send($email);

        return true;
    }
}
