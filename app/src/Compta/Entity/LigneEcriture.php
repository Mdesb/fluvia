<?php

declare(strict_types=1);

namespace App\Compta\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Ligne d'écriture (RG-M6-04) : porte son propre taux de TVA et ses axes analytiques ; aucune ligne
 * ne peut être enregistrée sans taux ni rattachement. Débit/crédit exclusifs (contrainte applicative
 * garantie par la construction des DTO du moteur de régime, jamais par saisie libre en L4).
 */
#[ORM\Entity]
#[ORM\Table(name: 'compta_ligne_ecriture')]
class LigneEcriture
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['ecriture:read', 'ligne:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: EcritureComptable::class, inversedBy: 'lignes')]
    #[ORM\JoinColumn(nullable: false)]
    private ?EcritureComptable $ecriture = null;

    #[ORM\ManyToOne(targetEntity: CompteComptable::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['ecriture:read', 'ligne:read'])]
    private ?CompteComptable $compte = null;

    #[ORM\Column]
    #[Groups(['ecriture:read', 'ligne:read'])]
    private int $debitCentimes = 0;

    #[ORM\Column]
    #[Groups(['ecriture:read', 'ligne:read'])]
    private int $creditCentimes = 0;

    #[ORM\ManyToOne(targetEntity: TauxTva::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['ecriture:read', 'ligne:read'])]
    private ?TauxTva $tauxTva = null;

    #[ORM\Column(length: 64, nullable: true)]
    #[Groups(['ecriture:read', 'ligne:read'])]
    private ?string $axeSite = null;

    #[ORM\Column(length: 64, nullable: true)]
    #[Groups(['ecriture:read', 'ligne:read'])]
    private ?string $axeActivite = null;

    #[ORM\Column(length: 64, nullable: true)]
    #[Groups(['ecriture:read', 'ligne:read'])]
    private ?string $axeFinanceur = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['ecriture:read', 'ligne:read'])]
    private ?string $libelle = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEcriture(): ?EcritureComptable
    {
        return $this->ecriture;
    }

    public function setEcriture(?EcritureComptable $ecriture): self
    {
        $this->ecriture = $ecriture;

        return $this;
    }

    public function getCompte(): ?CompteComptable
    {
        return $this->compte;
    }

    public function setCompte(?CompteComptable $compte): self
    {
        $this->compte = $compte;

        return $this;
    }

    public function getDebitCentimes(): int
    {
        return $this->debitCentimes;
    }

    public function setDebitCentimes(int $debitCentimes): self
    {
        $this->debitCentimes = $debitCentimes;

        return $this;
    }

    public function getCreditCentimes(): int
    {
        return $this->creditCentimes;
    }

    public function setCreditCentimes(int $creditCentimes): self
    {
        $this->creditCentimes = $creditCentimes;

        return $this;
    }

    public function getTauxTva(): ?TauxTva
    {
        return $this->tauxTva;
    }

    public function setTauxTva(?TauxTva $tauxTva): self
    {
        $this->tauxTva = $tauxTva;

        return $this;
    }

    public function getAxeSite(): ?string
    {
        return $this->axeSite;
    }

    public function setAxeSite(?string $axeSite): self
    {
        $this->axeSite = $axeSite;

        return $this;
    }

    public function getAxeActivite(): ?string
    {
        return $this->axeActivite;
    }

    public function setAxeActivite(?string $axeActivite): self
    {
        $this->axeActivite = $axeActivite;

        return $this;
    }

    public function getAxeFinanceur(): ?string
    {
        return $this->axeFinanceur;
    }

    public function setAxeFinanceur(?string $axeFinanceur): self
    {
        $this->axeFinanceur = $axeFinanceur;

        return $this;
    }

    public function getLibelle(): ?string
    {
        return $this->libelle;
    }

    public function setLibelle(?string $libelle): self
    {
        $this->libelle = $libelle;

        return $this;
    }
}
