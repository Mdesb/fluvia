<?php

declare(strict_types=1);

namespace App\RevenueRecovery\Service;

use App\Crm\Entity\Client;
use App\RevenueRecovery\Entity\RecoveryAttempt;
use App\RevenueRecovery\Entity\RecoveryCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Envoi e-mail d'une `RecoveryAttempt` (§9 spec, canal `email` seul en v1) — même patron que
 * `App\Boutique\Notification\RelancePanierExpireMailer` (n'envoie que si un contact e-mail est connu,
 * `false` sinon, jamais une exception).
 *
 * **Gabarit HTML minimal généré en PHP, pas de gabarit Twig dédié** (`templateCode` de l'étape n'est
 * qu'une étiquette portée dans le corps du message en I1) : ce lot n'ajoute aucun fichier sous
 * `app/templates/` (hors périmètre d'écriture de claude-E). Un futur lot pourra brancher un vrai gabarit
 * Twig par `templateCode` sans changer la signature de ce service.
 */
final class RecoveryAttemptMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly EntityManagerInterface $em,
        private readonly RecoverySubjectCustomerResolver $customerResolver,
    ) {
    }

    /** `false` si aucun destinataire connu (pas une erreur — §11 spec, contact non identifié). */
    public function send(RecoveryCase $case, RecoveryAttempt $attempt): bool
    {
        $etablissement = $case->getEstablishment();
        if ($etablissement === null) {
            return false;
        }

        $clientId = $this->customerResolver->resolveCustomerId($case->getSubjectType(), $case->getSubjectRef(), $etablissement->getId());
        if ($clientId === null) {
            return false;
        }

        $client = $this->em->getRepository(Client::class)->find($clientId);
        $destinataire = $client?->getEmail();
        if ($destinataire === null || '' === $destinataire) {
            return false;
        }

        $sequence = $case->getSequence();
        $step = $sequence?->getSteps()[$attempt->getStepIndex()] ?? null;
        $templateCode = \is_array($step) && \is_string($step['templateCode'] ?? null) ? $step['templateCode'] : 'default';

        $email = (new Email())
            ->to($destinataire)
            ->from('no-reply@itcotation.com')
            ->subject('Relance — ' . $templateCode)
            ->text('Ce message est une relance automatique (' . $case->getTriggerType()->value . ').')
            ->html(sprintf(
                '<p>Relance automatique — %s (%s)</p>',
                htmlspecialchars($templateCode, \ENT_QUOTES),
                htmlspecialchars($case->getTriggerType()->value, \ENT_QUOTES),
            ));

        $this->mailer->send($email);

        return true;
    }
}
