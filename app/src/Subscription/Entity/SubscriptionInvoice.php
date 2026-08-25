<?php

declare(strict_types=1);

namespace App\Subscription\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Le lien entre un abonnement, un mois, et la facture émise pour ce mois-là (ED-7).
 *
 * **Ce registre existe pour une seule raison : empêcher de facturer deux fois le même mois.** Un
 * ordonnanceur qui repasse, une relance manuelle après un doute, deux exploitants qui cliquent :
 * toutes ces situations arrivent, et aucune ne doit produire un second prélèvement. La contrainte
 * d'unicité sur `(abonnement, mois)` est ce qui l'interdit — pas une lecture préalable, qui laisserait
 * passer deux appels concurrents (même raisonnement que {@see ProvisioningRequest}).
 *
 * **Pourquoi ici et pas une colonne sur `Facture`.** Le module de facturation ne connaît pas les
 * abonnements, et il n'a pas à les connaître : il émet des factures pour n'importe quelle origine.
 * Lui ajouter une référence d'abonnement le coupleraient à un module qui l'utilise, alors que
 * l'inverse est vrai — c'est l'abonnement qui se sert de la facturation.
 *
 * **Le mois est stocké comme le premier jour du mois.** Une période de facturation est un mois entier,
 * pas un instant : normaliser à `AAAA-MM-01` rend la contrainte d'unicité exacte, là où une date
 * d'émission ferait de deux appels le 3 et le 4 deux périodes différentes.
 */
#[ORM\Entity]
#[ORM\Table(name: 'subscription_invoice')]
#[ORM\UniqueConstraint(name: 'uniq_subscription_invoice_periode', columns: ['subscription_id', 'period_start'])]
class SubscriptionInvoice
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Subscription::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Subscription $subscription = null;

    /** Premier jour du mois facturé. Voir le commentaire de classe. */
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $periodStart;

    /**
     * La facture émise, par son identifiant.
     *
     * Identifiant et non relation : `Facturation` est un autre module, et un abonnement n'a pas à
     * importer son entité pour exister — même discipline que `customerReference` sur
     * {@see Subscription}.
     */
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $invoiceId;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $issuedAt;

    /** Montant facturé en centimes, pour retrouver un écart sans rouvrir la facture. */
    #[ORM\Column(type: 'integer')]
    private int $totalCents = 0;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->periodStart = new \DateTimeImmutable('first day of this month');
        $this->invoiceId = Uuid::v4();
        $this->issuedAt = new \DateTimeImmutable();
    }

    /** Normalise n'importe quelle date en premier jour de son mois. */
    public static function debutDeMois(\DateTimeImmutable $quand): \DateTimeImmutable
    {
        return $quand->modify('first day of this month')->setTime(0, 0);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSubscription(): ?Subscription
    {
        return $this->subscription;
    }

    public function setSubscription(?Subscription $subscription): self
    {
        $this->subscription = $subscription;

        return $this;
    }

    public function getPeriodStart(): \DateTimeImmutable
    {
        return $this->periodStart;
    }

    public function setPeriodStart(\DateTimeImmutable $periodStart): self
    {
        $this->periodStart = self::debutDeMois($periodStart);

        return $this;
    }

    public function getInvoiceId(): Uuid
    {
        return $this->invoiceId;
    }

    public function setInvoiceId(Uuid $invoiceId): self
    {
        $this->invoiceId = $invoiceId;

        return $this;
    }

    public function getIssuedAt(): \DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function getTotalCents(): int
    {
        return $this->totalCents;
    }

    public function setTotalCents(int $totalCents): self
    {
        $this->totalCents = $totalCents;

        return $this;
    }
}
