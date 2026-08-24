<?php

declare(strict_types=1);

namespace App\Stay\Entity;

use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Une ligne du compte de séjour : ce que le client a consommé, et d'où ça vient (ACT-3, D16).
 *
 * **Elle porte son propre `establishment`, alors qu'elle pourrait le lire via son séjour.** C'est
 * exigé par D8 : toute entité résolue depuis un identifiant venu du client porte son propre contrôle
 * de périmètre. Une ligne récupérée par son id sans passer par le séjour serait sinon lisible d'un
 * établissement à l'autre — c'est exactement la famille de failles corrigée le 19/08, et le garde-fou
 * de cloisonnement la refuse à la poussée.
 *
 * **La contrainte d'unicité sur la source est le cœur du modèle.** Le bus est asynchrone depuis D7-bis
 * et son transport rejoue un message en cas d'échec ; sans cette contrainte, une consommation
 * relivrée deux fois facturerait deux fois le client. L'idempotence est donc garantie par le schéma,
 * pas par la vigilance du consommateur : c'est la seule protection qui survit à un bogue de listener.
 */
#[ORM\Entity]
#[ORM\Table(name: 'stay_charge')]
#[ORM\Index(columns: ['establishment_id', 'occurred_at'], name: 'IDX_STAY_CHARGE_ETAB_OCCURRED')]
#[ORM\UniqueConstraint(
    name: 'UNIQ_STAY_CHARGE_SOURCE',
    columns: ['stay_id', 'source_event', 'source_subject_id'],
)]
class StayCharge
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Stay::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Stay $stay;

    /** Redondant avec `stay.establishment` **par obligation** — voir le docblock de classe (D8). */
    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Etablissement $establishment;

    /**
     * Libellé tel qu'il apparaîtra sur la note. C'est une **donnée**, pas une chaîne d'interface :
     * « Bar — 2 demis » vient de la vente, pas du catalogue de traductions. Le garde-fou i18n ne
     * s'applique donc pas ici, et le traduire serait même une faute — une note doit rester lisible
     * telle qu'elle a été émise.
     */
    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $amount;

    /** Le fait métier daté — l'heure de la consommation, pas celle de l'enregistrement. */
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $occurredAt;

    /** Module émetteur (`vente`, `acces`, `reservation`…), pour la ventilation comptable. */
    #[ORM\Column(length: 32)]
    private string $sourceModule;

    /**
     * Nom de l'événement d'origine, tel que catalogué (`sale.completed`…). Avec `sourceSubjectId`, il
     * forme la clé d'idempotence.
     */
    #[ORM\Column(length: 64)]
    private string $sourceEvent;

    /** Identifiant du sujet de l'événement — la vente, le passage, la réservation. */
    #[ORM\Column(length: 64)]
    private string $sourceSubjectId;

    public function __construct(
        Stay $stay,
        string $label,
        string $amount,
        \DateTimeImmutable $occurredAt,
        string $sourceModule,
        string $sourceEvent,
        string $sourceSubjectId,
    ) {
        if (!$stay->acceptsCharges()) {
            throw new \LogicException(sprintf(
                'Le séjour %s n\'accepte plus de ligne (état : %s).',
                $stay->getReference(),
                $stay->getStatus()->value,
            ));
        }

        $this->id = Uuid::v7();
        $this->stay = $stay;
        $this->establishment = $stay->getEstablishment();
        $this->label = $label;
        $this->amount = $amount;
        $this->occurredAt = $occurredAt;
        $this->sourceModule = $sourceModule;
        $this->sourceEvent = $sourceEvent;
        $this->sourceSubjectId = $sourceSubjectId;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getStay(): Stay
    {
        return $this->stay;
    }

    public function getEstablishment(): Etablissement
    {
        return $this->establishment;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getSourceModule(): string
    {
        return $this->sourceModule;
    }

    public function getSourceEvent(): string
    {
        return $this->sourceEvent;
    }

    public function getSourceSubjectId(): string
    {
        return $this->sourceSubjectId;
    }
}
