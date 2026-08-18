<?php

declare(strict_types=1);

namespace App\Facturation\Entity;

use App\Facturation\Service\Montant;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Règlement enregistré sur une facture directe (RG-FACT-06, `plan-facturation.md` §1.5).
 *
 * Addition technique assumée par le plan : `App\Compta\Entity\LettrageEcriture` (M6) ne porte pas
 * de montant — c'est un marqueur « ligne soldée », tout ou rien. Facturation trace donc ses
 * règlements **partiels** dans son propre domaine et n'appelle `LettrageHandler::lettrer()`
 * **qu'une seule fois**, quand le cumul atteint le total TTC. Aucun second mécanisme de lettrage
 * n'est créé : la réconciliation comptable finale reste portée par M6.
 */
#[ORM\Entity]
#[ORM\Table(name: 'facturation_reglement')]
class ReglementFacture
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['facture:read', 'reglement:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Facture::class, inversedBy: 'reglements')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Facture $facture = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Assert\NotBlank]
    #[Groups(['facture:read', 'reglement:read'])]
    private string $montant = Montant::ZERO;

    /** Code du moyen de paiement (référentiel M6 réutilisé en clair, cf. `Paiement::$moyenCode`). */
    #[ORM\Column(length: 32)]
    #[Assert\NotBlank]
    #[Groups(['facture:read', 'reglement:read'])]
    private string $moyen = 'virement';

    #[ORM\Column(length: 64, nullable: true)]
    #[Groups(['facture:read', 'reglement:read'])]
    private ?string $reference = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['facture:read', 'reglement:read'])]
    private \DateTimeImmutable $dateReglement;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['reglement:read'])]
    private ?Utilisateur $auteur = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateReglement = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getFacture(): ?Facture
    {
        return $this->facture;
    }

    public function setFacture(?Facture $facture): self
    {
        $this->facture = $facture;

        return $this;
    }

    public function getMontant(): string
    {
        return $this->montant;
    }

    public function setMontant(string $montant): self
    {
        $this->montant = Montant::normaliser($montant);

        return $this;
    }

    public function getMoyen(): string
    {
        return $this->moyen;
    }

    public function setMoyen(string $moyen): self
    {
        $this->moyen = $moyen;

        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): self
    {
        $this->reference = $reference;

        return $this;
    }

    public function getDateReglement(): \DateTimeImmutable
    {
        return $this->dateReglement;
    }

    public function setDateReglement(\DateTimeImmutable $dateReglement): self
    {
        $this->dateReglement = $dateReglement;

        return $this;
    }

    public function getAuteur(): ?Utilisateur
    {
        return $this->auteur;
    }

    public function setAuteur(?Utilisateur $auteur): self
    {
        $this->auteur = $auteur;

        return $this;
    }
}
