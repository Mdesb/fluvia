<?php

declare(strict_types=1);

namespace App\Sepa\Entity;

use App\Platform\Notification\NotificationOutcome;
use App\Sepa\Enum\PreNotificationReason;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Le préavis envoyé à un client avant qu'on prélève sur son compte.
 *
 * **Ce que la règle exige, et ce que le dépôt faisait.** Un créancier SEPA doit informer le débiteur
 * du montant et de la date avant chaque prélèvement — quatorze jours calendaires sauf autre délai
 * convenu. Le module SEPA de ce dépôt n'émettait **rien** : ni notification, ni événement. Les
 * remises partaient donc sans qu'aucun client n'ait été prévenu, et la seule trace d'un prélèvement à
 * venir était la remise elle-même, que le client ne voit pas.
 *
 * **Pourquoi une entité et pas un simple envoi.** Un préavis qu'on envoie sans le consigner ne peut ni
 * être opposé en cas de contestation, ni surtout **conditionner** le prélèvement. C'est ce
 * conditionnement qui fait la différence entre un mécanisme et une intention : `DebitPreNotifier`
 * relit cette table avant d'autoriser une collecte, et refuse si elle ne couvre pas le prélèvement.
 *
 * **Le montant fait partie du préavis.** Annoncer trente euros puis en prélever trois cents n'est pas
 * un préavis, c'est un préavis pour autre chose. `announce()` réécrit donc l'enregistrement dès que le
 * montant change, ce qui remet le délai à zéro — un client à qui l'on change la somme doit disposer du
 * même temps qu'au premier jour.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sepa_debit_prenotification')]
#[ORM\UniqueConstraint(name: 'uniq_prenotification_mandate_origin', columns: ['mandate_id', 'origin_reference'])]
class DebitPreNotification
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: MandatSepa::class)]
    #[ORM\JoinColumn(name: 'mandate_id', nullable: false)]
    private ?MandatSepa $mandate = null;

    /**
     * L'identifiant opaque que la verticale donne à son échéance.
     *
     * Même convention que `EcheanceSepaDue::referenceOrigine` : le module SEPA ne connaît aucun type
     * métier de Sport ou de Piscine, et ne doit pas commencer ici.
     */
    #[ORM\Column(name: 'origin_reference', length: 64)]
    private string $originReference = '';

    #[ORM\Column(name: 'amount_cents')]
    private int $amountCents = 0;

    /** La date annoncée au client. Prélever après, c'est toléré ; avant, non. */
    #[ORM\Column(name: 'announced_due_date', type: 'date_immutable')]
    private \DateTimeImmutable $announcedDueDate;

    #[ORM\Column(name: 'sent_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $sentAt;

    #[ORM\Column(name: 'reason', length: 20, enumType: PreNotificationReason::class)]
    private PreNotificationReason $reason = PreNotificationReason::Schedule;

    /**
     * Ce qu'il est advenu de l'envoi.
     *
     * **Consigné, parce que `Journalisee` n'est pas `Envoyee`.** Aucun prestataire d'envoi n'est
     * branché dans ce dépôt (D19, D42 `CMP-6`) : l'adaptateur par défaut journalise. Sans ce champ,
     * la table dirait « prévenu » d'un client qui n'a rien reçu, et le prélèvement suivrait. Il est
     * gardé plutôt que jeté pour qu'un exploitant puisse compter ses préavis en souffrance et
     * comprendre pourquoi rien ne part.
     */
    #[ORM\Column(name: 'outcome', length: 20, enumType: NotificationOutcome::class)]
    private NotificationOutcome $outcome = NotificationOutcome::Journalisee;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->announcedDueDate = new \DateTimeImmutable();
        $this->sentAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMandate(): ?MandatSepa
    {
        return $this->mandate;
    }

    public function setMandate(?MandatSepa $mandate): self
    {
        $this->mandate = $mandate;

        return $this;
    }

    public function getOriginReference(): string
    {
        return $this->originReference;
    }

    public function setOriginReference(string $originReference): self
    {
        $this->originReference = $originReference;

        return $this;
    }

    public function getAmountCents(): int
    {
        return $this->amountCents;
    }

    public function setAmountCents(int $amountCents): self
    {
        $this->amountCents = $amountCents;

        return $this;
    }

    public function getAnnouncedDueDate(): \DateTimeImmutable
    {
        return $this->announcedDueDate;
    }

    public function setAnnouncedDueDate(\DateTimeImmutable $announcedDueDate): self
    {
        $this->announcedDueDate = $announcedDueDate;

        return $this;
    }

    public function getSentAt(): \DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(\DateTimeImmutable $sentAt): self
    {
        $this->sentAt = $sentAt;

        return $this;
    }

    public function getReason(): PreNotificationReason
    {
        return $this->reason;
    }

    public function getOutcome(): NotificationOutcome
    {
        return $this->outcome;
    }

    public function setOutcome(NotificationOutcome $outcome): self
    {
        $this->outcome = $outcome;

        return $this;
    }

    /**
     * Le client a-t-il vraiment recu ce preavis ?
     *
     * Seul `Envoyee` compte. Un preavis journalise existe, se compte et s explique, mais il ne
     * prouve rien : personne ne l a lu.
     */
    public function wasDelivered(): bool
    {
        return NotificationOutcome::Envoyee === $this->outcome;
    }

    public function setReason(PreNotificationReason $reason): self
    {
        $this->reason = $reason;

        return $this;
    }
}
