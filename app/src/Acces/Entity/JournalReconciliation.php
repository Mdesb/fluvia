<?php

declare(strict_types=1);

namespace App\Acces\Entity;

use App\Acces\Enum\StatutJournalReconciliation;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Litige de réconciliation double-consommation hors-ligne (US-TERM-08, CA-8, plan-acces-terminal.md
 * §1.2/§4.2). Créée uniquement quand la réconciliation gracieuse du crédit épuisé hors-ligne est
 * activée (`EvenementPassageDto::autoriserCreditNegatifSiHorsLigne`, flag désactivé par défaut, cf.
 * Risque R-6) : trace un passage accepté malgré un dépassement de crédit détecté au rejeu. Traitement
 * par un agent (régularisation/ignoré) hors périmètre de ce plan.
 *
 * Aucune opération API Platform n'expose cette entité pour l'instant (hors périmètre, cf. plan §2) :
 * simple table d'audit, consultable ultérieurement par un écran de régularisation (M2/L3).
 */
#[ORM\Entity]
#[ORM\Table(name: 'acces_journal_reconciliation')]
#[ORM\Index(columns: ['passage_id'], name: 'idx_journal_passage')]
#[ORM\Index(columns: ['droit_id'], name: 'idx_journal_droit')]
#[ORM\Index(columns: ['statut'], name: 'idx_journal_statut')]
class JournalReconciliation
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Passage::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Passage $passage = null;

    #[ORM\ManyToOne(targetEntity: DroitAcces::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?DroitAcces $droit = null;

    /** Ampleur du dépassement au moment du rejeu (ex. `-1`). */
    #[ORM\Column]
    private int $ecart = 0;

    #[ORM\Column(length: 12, enumType: StatutJournalReconciliation::class, options: ['default' => 'ouvert'])]
    private StatutJournalReconciliation $statut = StatutJournalReconciliation::Ouvert;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $horodatage;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->horodatage = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPassage(): ?Passage
    {
        return $this->passage;
    }

    public function setPassage(?Passage $passage): self
    {
        $this->passage = $passage;

        return $this;
    }

    public function getDroit(): ?DroitAcces
    {
        return $this->droit;
    }

    public function setDroit(?DroitAcces $droit): self
    {
        $this->droit = $droit;

        return $this;
    }

    public function getEcart(): int
    {
        return $this->ecart;
    }

    public function setEcart(int $ecart): self
    {
        $this->ecart = $ecart;

        return $this;
    }

    public function getStatut(): StatutJournalReconciliation
    {
        return $this->statut;
    }

    public function setStatut(StatutJournalReconciliation $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getHorodatage(): \DateTimeImmutable
    {
        return $this->horodatage;
    }

    public function setHorodatage(\DateTimeImmutable $horodatage): self
    {
        $this->horodatage = $horodatage;

        return $this;
    }

    public function getEtablissement(): ?Etablissement
    {
        return $this->etablissement;
    }

    public function setEtablissement(?Etablissement $etablissement): self
    {
        $this->etablissement = $etablissement;

        return $this;
    }
}
