<?php

declare(strict_types=1);

namespace App\Sepa\Service;

use App\Platform\Notification\ClientNotification;
use App\Platform\Notification\ClientNotifierInterface;
use App\Platform\Notification\NotificationBasis;
use App\Platform\Notification\NotificationChannel;
use App\Platform\Notification\NotificationOutcome;
use App\Sepa\Entity\ConfigCreancierSepa;
use App\Sepa\Entity\DebitPreNotification;
use App\Sepa\Entity\MandatSepa;
use App\Sepa\Enum\PreNotificationReason;
use App\Sepa\Exception\PreNotificationRefusedException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Prévient le client avant qu'on prélève, et refuse de prélever si on ne l'a pas prévenu.
 *
 * **Les deux moitiés vivent ici, et c'est délibéré.** Un service qui se contenterait d'envoyer aurait
 * produit un préavis décoratif : rien n'aurait empêché une remise de partir sans lui. `covers()` est
 * la moitié qui donne son sens à `announce()` — sans elle, on aurait ajouté une table à alimenter, pas
 * une garantie.
 *
 * **Le fondement est contractuel, pas consenti — et c'est une exigence, pas un confort.** Le point de
 * passage unique de `Platform` refuse par défaut faute de consentement (D42). Sur cette base, un
 * client ayant refusé la prospection ne recevrait jamais son préavis, et le prélèvement qui suivrait
 * serait irrégulier. Prévenir quelqu'un qu'on va débiter son compte n'est pas de la sollicitation :
 * c'est l'obligation qui rend le débit licite.
 *
 * **Ce qui n'est pas parti n'autorise rien.** `NotificationOutcome` distingue `Envoyee` de
 * `Journalisee`, et son propre commentaire dit pourquoi : aucun prestataire d'envoi n'est branché dans
 * ce dépôt (D19, D42 `CMP-6`), donc l'adaptateur par défaut journalise. Un préavis journalisé est
 * consigné — il faut pouvoir le compter et l'expliquer — mais `covers()` le rejette. Conséquence
 * assumée : **tant qu'aucun prestataire n'est branché, aucun prélèvement ne franchit ce contrôle.**
 * C'est le bon sens de la panne. L'inverse laisserait débiter des gens que personne n'a prévenus, sur
 * la foi d'une ligne de journal.
 */
final class DebitPreNotifier
{
    /** Délai retenu quand l'établissement n'en a pas fixé : la règle SEPA par défaut. */
    public const DEFAULT_DELAY_DAYS = 14;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClientNotifierInterface $notifier,
    ) {
    }

    /**
     * Annonce un prélèvement à venir : envoie le préavis, puis consigne ce qu'il en est advenu.
     *
     * Réécrit l'annonce existante pour ce couple (mandat, référence) plutôt que d'en empiler une
     * seconde. Deux préavis contradictoires pour la même échéance ne diraient plus lequel fait foi ;
     * et comme `sentAt` repart, un changement de montant rend au client la totalité du délai.
     *
     * @throws PreNotificationRefusedException si le mandat ne mène à aucun client, ou si l'envoi a été
     *                                         refusé ou a échoué
     */
    public function announce(
        MandatSepa $mandate,
        string $originReference,
        int $amountCents,
        \DateTimeImmutable $announcedDueDate,
        PreNotificationReason $reason,
        \DateTimeImmutable $at,
    ): DebitPreNotification {
        $client = $mandate->getClient();
        if (null === $client) {
            throw new PreNotificationRefusedException(sprintf(
                'Mandat « %s » : aucun client rattaché, donc personne à prévenir.',
                $mandate->getRum(),
            ));
        }

        $issue = $this->notifier->notify(new ClientNotification(
            clientId: $client->getId(),
            channel: NotificationChannel::Email,
            templateKey: $reason->templateKey(),
            variables: [
                'rum' => $mandate->getRum(),
                'ics' => $this->ics($mandate),
                'amountCents' => $amountCents,
                'dueDate' => $announcedDueDate->format('Y-m-d'),
                'ibanLast4' => $mandate->getIban4Derniers(),
            ],
            // L'instant métier est celui où l'on annonce, pas l'heure d'exécution du traitement (D37).
            occurredAt: $at,
            source: 'sepa.prenotification',
            basis: NotificationBasis::Contractuelle,
        ));

        // `Refusee` et `Echouee` ne laissent aucune trace : consigner un préavis qui n'a pas eu lieu
        // ferait croire à l'exploitant qu'il a prévenu. `Journalisee` se consigne, mais ne couvrira
        // aucun prélèvement — voir `covers()`.
        if (NotificationOutcome::Refusee === $issue || NotificationOutcome::Echouee === $issue) {
            throw new PreNotificationRefusedException(sprintf(
                'Mandat « %s » : le préavis n\'est pas parti (%s). Rien n\'est consigné, donc rien ne '
                .'sera prélevé sur cette échéance.',
                $mandate->getRum(),
                $issue->value,
            ));
        }

        $preavis = $this->existing($mandate, $originReference) ?? new DebitPreNotification();
        $preavis->setMandate($mandate)
            ->setOriginReference($originReference)
            ->setAmountCents($amountCents)
            ->setAnnouncedDueDate($announcedDueDate)
            ->setReason($reason)
            ->setOutcome($issue)
            ->setSentAt($at);

        $this->em->persist($preavis);
        $this->em->flush();

        return $preavis;
    }

    /**
     * Le client a-t-il été prévenu de CE prélèvement-là, assez tôt, et pour de bon ?
     *
     * Quatre conditions, et aucune n'est superflue :
     *  - le préavis existe pour ce couple (mandat, référence) ;
     *  - il est réellement parti (`Envoyee`), et non simplement journalisé ;
     *  - il annonce **ce montant** — annoncer trente euros puis en prélever trois cents n'est pas un
     *    préavis, c'est un préavis pour autre chose ;
     *  - il est parti au moins `delayDays` avant l'exécution, et la date annoncée n'est pas postérieure
     *    à celle-ci : être débité plus tard qu'annoncé se tolère, plus tôt jamais.
     */
    public function covers(
        MandatSepa $mandate,
        string $originReference,
        int $amountCents,
        \DateTimeImmutable $executionDate,
        ?int $delayDays = null,
    ): bool {
        $preavis = $this->existing($mandate, $originReference);

        if (null === $preavis || !$preavis->wasDelivered()) {
            return false;
        }

        if ($preavis->getAmountCents() !== $amountCents) {
            return false;
        }

        $auPlusTard = $executionDate->modify(sprintf('-%d days', $delayDays ?? $this->delayFor($mandate)));

        return $preavis->getSentAt() <= $auPlusTard
            && $preavis->getAnnouncedDueDate() <= $executionDate;
    }

    /** La première date à laquelle un préavis émis maintenant autoriserait un prélèvement. */
    public function earliestDebitDate(MandatSepa $mandate, \DateTimeImmutable $at): \DateTimeImmutable
    {
        return $at->modify(sprintf('+%d days', $this->delayFor($mandate)));
    }

    public function delayFor(MandatSepa $mandate): int
    {
        return $this->config($mandate)?->getPreNotificationDelayDays() ?? self::DEFAULT_DELAY_DAYS;
    }

    private function existing(MandatSepa $mandate, string $originReference): ?DebitPreNotification
    {
        return $this->em->getRepository(DebitPreNotification::class)->findOneBy([
            'mandate' => $mandate,
            'originReference' => $originReference,
        ]);
    }

    private function config(MandatSepa $mandate): ?ConfigCreancierSepa
    {
        $etablissement = $mandate->getEtablissement();
        if (null === $etablissement) {
            return null;
        }

        return $this->em->getRepository(ConfigCreancierSepa::class)->findOneBy(['etablissement' => $etablissement]);
    }

    private function ics(MandatSepa $mandate): string
    {
        return $this->config($mandate)?->getIcs() ?? '';
    }
}
