<?php

declare(strict_types=1);

namespace App\Dining\Entity;

use App\Dining\Domain\CourseRef;
use App\Dining\Enum\LineStatus;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Une ligne de commande a table (ACT-4, D16).
 *
 * **L envoi en cuisine est le seul moment irreversible du module**, et toutes les regles qui suivent
 * en decoulent. Avant l envoi, la ligne n existe qu a l ecran du serveur : on la corrige, on l efface,
 * personne n en saura rien. Apres l envoi, un cuisinier a saisi une poele — le produit est sorti du
 * stock, qu on le serve ou qu on le jette.
 *
 * C est le meme partage que celui pose sur le stock : **on refuse ce qui pretend defaire un fait, on
 * n empeche jamais de le constater.** Une ligne envoyee ne se retire pas, elle s annule ; et une
 * annulation apres envoi est une perte, pas une gomme.
 *
 * **Elle porte son propre `establishment`** alors qu elle pourrait le lire via sa commande. Exige par
 * D8 : une ligne recuperee par son id sans passer par l addition serait sinon lisible d un
 * etablissement a l autre.
 *
 * **Le service est stocke en deux colonnes plutot qu en relation** — un code et un rang. Le
 * referentiel des services est libre (`CourseRef`) : une table dediee obligerait chaque exploitant a
 * declarer « entree » avant de pouvoir saisir une entree, pour un gain nul.
 */
#[ORM\Entity]
#[ORM\Table(name: 'dining_order_line')]
#[ORM\Index(columns: ['dining_order_id'], name: 'IDX_DINING_LINE_ORDER')]
#[ORM\Index(columns: ['establishment_id', 'status'], name: 'IDX_DINING_LINE_ETAB_STATUS')]
class DiningOrderLine
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: DiningOrder::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DiningOrder $diningOrder;

    /** Redondant avec la commande **par obligation** — voir le docblock de classe (D8). */
    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Etablissement $establishment;

    #[ORM\Column(length: 40)]
    private string $courseCode;

    /** Ordonne l envoi. Deux services peuvent partager un rang — fromage et dessert ensemble. */
    #[ORM\Column]
    private int $courseRank;

    /**
     * Le libelle tel qu il s imprime sur le bon de cuisine et sur l addition. C est une **donnee**,
     * pas une chaine d interface : « Entrecote, saignante » vient de la salle, pas du catalogue de
     * traductions, et la traduire serait une faute.
     */
    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column]
    private int $quantity;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $unitAmount;

    #[ORM\Column(length: 16, enumType: LineStatus::class, options: ['default' => 'draft'])]
    private LineStatus $status = LineStatus::Draft;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $firedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $voidReason = null;

    public function __construct(
        DiningOrder $diningOrder,
        CourseRef $course,
        string $label,
        int $quantity,
        string $unitAmount,
    ) {
        if (!$diningOrder->acceptsLines()) {
            throw new \LogicException(sprintf(
                'Addition %s close (etat : %s) : elle n accepte plus de commande.',
                $diningOrder->getReference(),
                $diningOrder->getStatus()->value,
            ));
        }
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Une ligne de commande porte au moins une unite.');
        }

        $this->id = Uuid::v7();
        $this->diningOrder = $diningOrder;
        $this->establishment = $diningOrder->getEstablishment();
        $this->courseCode = $course->code;
        $this->courseRank = $course->rank;
        $this->label = $label;
        $this->quantity = $quantity;
        $this->unitAmount = $unitAmount;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDiningOrder(): DiningOrder
    {
        return $this->diningOrder;
    }

    public function getEstablishment(): Etablissement
    {
        return $this->establishment;
    }

    public function getCourse(): CourseRef
    {
        return CourseRef::of($this->courseCode, $this->courseRank);
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getUnitAmount(): string
    {
        return $this->unitAmount;
    }

    public function getStatus(): LineStatus
    {
        return $this->status;
    }

    public function getFiredAt(): ?\DateTimeImmutable
    {
        return $this->firedAt;
    }

    public function getVoidReason(): ?string
    {
        return $this->voidReason;
    }

    /**
     * Envoi en cuisine.
     *
     * **Refuse le second envoi**, et c est la regle la plus concrete du module : un service envoye
     * deux fois, ce sont deux fois les plats qui sortent. Le cuisinier ne peut pas savoir que le
     * second bon est un doublon — il voit une commande, il la prepare.
     */
    public function fire(\DateTimeImmutable $at): self
    {
        if (LineStatus::Draft !== $this->status) {
            throw new \LogicException(sprintf(
                'La ligne « %s » a deja quitte le brouillon (etat : %s) : la renvoyer ferait sortir les plats deux fois.',
                $this->label,
                $this->status->value,
            ));
        }

        $this->status = LineStatus::Fired;
        $this->firedAt = $at;

        return $this;
    }

    public function serve(): self
    {
        if (LineStatus::Fired !== $this->status) {
            throw new \LogicException(sprintf(
                'Seule une ligne envoyee en cuisine peut etre servie (etat : %s).',
                $this->status->value,
            ));
        }

        $this->status = LineStatus::Served;

        return $this;
    }

    /** Une ligne encore au brouillon s efface sans laisser de trace : rien n a ete engage. */
    public function isRemovable(): bool
    {
        return LineStatus::Draft === $this->status;
    }

    /**
     * Annulation apres envoi. **Exige un motif**, et la ligne reste consommee.
     *
     * Le motif n est pas une formalite : c est la seule chose qui distingue une erreur de saisie d un
     * plat renvoye par le client, et ces deux-la ne se comprennent pas pareil au moment d expliquer
     * une perte en fin de mois.
     */
    public function void(string $reason): self
    {
        if (LineStatus::Draft === $this->status) {
            throw new \LogicException(
                'Une ligne au brouillon se retire, elle ne s annule pas : rien n a encore ete engage.',
            );
        }
        if (LineStatus::Voided === $this->status) {
            throw new \LogicException('Cette ligne est deja annulee.');
        }
        if ('' === trim($reason)) {
            throw new \InvalidArgumentException(
                'Une annulation apres envoi exige un motif : il distingue l erreur de saisie du plat renvoye.',
            );
        }

        $this->status = LineStatus::Voided;
        $this->voidReason = trim($reason);

        return $this;
    }

    /** Compte-t-elle dans l addition du client ? */
    public function isBillable(): bool
    {
        return LineStatus::Voided !== $this->status;
    }

    /**
     * A-t-elle consomme de la matiere ?
     *
     * Vrai des l envoi, **y compris apres annulation** : c est ce qui empeche une addition corrigee
     * de faire disparaitre une sortie de stock bien reelle.
     */
    public function hasConsumed(): bool
    {
        return LineStatus::Draft !== $this->status;
    }
}
