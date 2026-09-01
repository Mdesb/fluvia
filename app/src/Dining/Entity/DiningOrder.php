<?php

declare(strict_types=1);

namespace App\Dining\Entity;

use App\Dining\Enum\OrderStatus;
use App\Organisation\Entity\Etablissement;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * La commande d'une table — ce qu'on appelle « l'addition » en salle (ACT-4, D16).
 *
 * **Elle est attachée à une table et à un moment, pas à une réservation.** Ce n'est pas un
 * contournement du couplage entre modules : c'est le métier. À midi, la majorité des tables sont des
 * clients entrés sans avoir réservé. Une réservation peut exister par ailleurs — `claude-G` la porte,
 * avec ses huit couverts consommés sur les soixante du service — mais l'addition ne lui appartient
 * pas : elle appartient à la table qui mange maintenant.
 *
 * **Le libellé de table est une chaîne libre**, et volontairement. Une salle numérote « 12 », une
 * terrasse dit « T3 », un bar dit « comptoir ». Contraindre ce vocabulaire obligerait chaque
 * exploitant à traduire son plan de salle dans le nôtre — ce que D15 reproche à l'énumération `Metier`.
 *
 * **Trois états, pour la même raison que le séjour** : demander l'addition et la payer sont deux faits
 * distincts. Une table part parfois avant d'avoir réglé — note de frais d'entreprise, litige, client
 * qui revient le lendemain.
 */
#[ORM\Entity]
#[ORM\Table(name: 'dining_order')]
#[ORM\Index(columns: ['establishment_id', 'status'], name: 'IDX_DINING_ORDER_ETAB_STATUS')]
#[ORM\Index(columns: ['establishment_id', 'opened_at'], name: 'IDX_DINING_ORDER_ETAB_OPENED')]
#[ORM\UniqueConstraint(name: 'UNIQ_DINING_ORDER_ETAB_REFERENCE', columns: ['establishment_id', 'reference'])]
class DiningOrder
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    /**
     * Périmètre de la commande. **Immuable, et jamais exposé en écriture** (D41) : déplacer une
     * addition d'un établissement à l'autre déplacerait avec elle des lignes déjà encaissées ailleurs.
     */
    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Etablissement $establishment;

    #[ORM\Column(length: 32)]
    private string $reference;

    /** Le nom que la salle donne à cette table : « 12 », « T3 », « comptoir ». */
    #[ORM\Column(length: 64)]
    private string $tableLabel;

    /**
     * Le nombre de couverts.
     *
     * Redondant avec la réservation quand il y en a une — mais une table sur deux n'en a pas, et le
     * chiffre sert au ticket de cuisine comme au chiffre d'affaires par couvert, qui est l'indicateur
     * que regarde un restaurateur.
     */
    #[ORM\Column(options: ['default' => 1])]
    private int $covers = 1;

    #[ORM\Column(length: 16, enumType: OrderStatus::class, options: ['default' => 'open'])]
    private OrderStatus $status = OrderStatus::Open;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $openedAt;

    /** L'addition a été demandée et figée. Peut rester impayée — voir le docblock de classe. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $settledAt = null;

    /** @var Collection<int, DiningOrderLine> */
    #[ORM\OneToMany(mappedBy: 'diningOrder', targetEntity: DiningOrderLine::class)]
    private Collection $lines;

    public function __construct(
        Etablissement $establishment,
        string $reference,
        string $tableLabel,
        int $covers,
        \DateTimeImmutable $openedAt,
    ) {
        if ($covers < 1) {
            throw new \InvalidArgumentException('Une table compte au moins un couvert.');
        }

        $this->id = Uuid::v7();
        $this->establishment = $establishment;
        $this->reference = $reference;
        $this->tableLabel = $tableLabel;
        $this->covers = $covers;
        $this->openedAt = $openedAt;
        $this->lines = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEstablishment(): Etablissement
    {
        return $this->establishment;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getTableLabel(): string
    {
        return $this->tableLabel;
    }

    public function getCovers(): int
    {
        return $this->covers;
    }

    public function getStatus(): OrderStatus
    {
        return $this->status;
    }

    public function getOpenedAt(): \DateTimeImmutable
    {
        return $this->openedAt;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function getSettledAt(): ?\DateTimeImmutable
    {
        return $this->settledAt;
    }

    /** @return Collection<int, DiningOrderLine> */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    /** Une addition close n'accepte plus de commande : c'est ce que `DiningOrderLine` suppose. */
    public function acceptsLines(): bool
    {
        return OrderStatus::Open === $this->status;
    }

    /** L'addition est demandée et figée. Idempotent : en plein coup de feu, on clique deux fois. */
    public function close(\DateTimeImmutable $closedAt): self
    {
        if (OrderStatus::Open === $this->status) {
            $this->status = OrderStatus::Closed;
            $this->closedAt = $closedAt;
        }

        return $this;
    }

    /**
     * L'addition est réglée. Une table encore ouverte ne peut pas être soldée : elle accepterait une
     * commande juste après, et le montant encaissé serait déjà faux.
     */
    public function settle(\DateTimeImmutable $settledAt): self
    {
        if (OrderStatus::Closed !== $this->status) {
            throw new \LogicException(sprintf(
                'Une addition doit être demandée avant d\'être réglée (état courant : %s).',
                $this->status->value,
            ));
        }

        $this->status = OrderStatus::Settled;
        $this->settledAt = $settledAt;

        return $this;
    }
}
