<?php

declare(strict_types=1);

namespace App\Sepa\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Une somme qu'une carte a refusé de payer, et qu'on prélèvera sur le mandat du client (PAY-2, D43).
 *
 * **Pourquoi une dette et pas un simple nouvel essai.** Une carte refusée ne se rejoue pas : le refus
 * vient de la banque du porteur, et réessayer la même carte donnera le même refus. La bascule change
 * de moyen, pas de tentative — elle s'appuie sur un mandat **déjà signé**, ce qui est la raison pour
 * laquelle les deux moyens sont collectés ensemble à la souscription.
 *
 * **La dette n'est pas prélevable tout de suite, et c'est le fond du lot.** `dueDate` est la première
 * date à laquelle le préavis aura couru — quatorze jours par défaut. Prélever avant serait un
 * prélèvement dont le client n'a pas été prévenu, sur un moyen qu'il ne s'attendait pas à voir
 * utiliser ce mois-ci. C'est exactement la situation où l'absence de préavis se conteste.
 *
 * **`originReference` est ce qui relie les trois moments** : le préavis annoncé, l'échéance présentée
 * à la collecte, et cette dette. Les trois doivent porter la même chaîne et le même montant, sans quoi
 * `DebitPreNotifier::covers()` reconnaîtrait « une annonce qui ressemble à la bonne sans en être une »
 * — la faute la plus difficile à voir, parce que tout serait vert.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sepa_card_fallback_debt')]
// Pose par migration le 26/08 et jamais declare : Doctrine voulait le SUPPRIMER. Il sert
// la seule requete chaude du lot -- les dettes echues et non encore collectees.
#[ORM\Index(columns: ['due_date', 'collected_at'], name: 'idx_card_fallback_due')]
#[ORM\UniqueConstraint(name: 'uniq_card_fallback_mandate_origin', columns: ['mandate_id', 'origin_reference'])]
class CardFallbackDebt
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: MandatSepa::class)]
    #[ORM\JoinColumn(name: 'mandate_id', nullable: false)]
    private ?MandatSepa $mandate = null;

    /** L'identifiant du paiement refusé, fourni par la caisse. Même chaîne que le préavis. */
    #[ORM\Column(name: 'origin_reference', length: 64)]
    private string $originReference = '';

    #[ORM\Column(name: 'amount_cents')]
    private int $amountCents = 0;

    /** La première date à laquelle le préavis aura couru. Jamais avant. */
    #[ORM\Column(name: 'due_date', type: 'date_immutable')]
    private \DateTimeImmutable $dueDate;

    /** La facture concernée, si la bascule vient d'une facture. Identifiant et non relation (D-FAC). */
    #[ORM\Column(name: 'invoice_id', type: UuidType::NAME, nullable: true)]
    private ?Uuid $invoiceId = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** Nul tant que la dette n'est pas partie en remise. C'est ce qui la rend visible tant qu'elle traîne. */
    #[ORM\Column(name: 'collected_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $collectedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->dueDate = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
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

    public function getDueDate(): \DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function setDueDate(\DateTimeImmutable $dueDate): self
    {
        $this->dueDate = $dueDate;

        return $this;
    }

    public function getInvoiceId(): ?Uuid
    {
        return $this->invoiceId;
    }

    public function setInvoiceId(?Uuid $invoiceId): self
    {
        $this->invoiceId = $invoiceId;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getCollectedAt(): ?\DateTimeImmutable
    {
        return $this->collectedAt;
    }

    public function setCollectedAt(?\DateTimeImmutable $collectedAt): self
    {
        $this->collectedAt = $collectedAt;

        return $this;
    }
}
