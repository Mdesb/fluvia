<?php

declare(strict_types=1);

namespace App\Piscine\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Organisation\Entity\Etablissement;
use App\Piscine\Enum\StatutCaution;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Caution consignée à l'attribution d'un casier (décision actée « casier non rendu », US-L6-09).
 * Une seule caution *active* (statut ≠ libérée) par casier à la fois : garanti par la colonne
 * dénormalisée `casierActif` (= id du casier tant que non libérée, NULL sinon) sous contrainte
 * unique — même technique que `Appairage.supportActif` (L3 §1.2).
 *
 * ⚠ HYPOTHÈSE — `moyenEncaissement` (empreinte CB, espèces, PMV M4…) et `regieMouvementRef`
 * (référence logique vers un mouvement de régie/M6/M2) ne sont pas précisés par les sources (spec
 * §4.9, plan risques n°6/8) — à harmoniser transversalement.
 */
#[ORM\Entity]
#[ORM\Table(name: 'piscine_caution_casier')]
#[ORM\UniqueConstraint(name: 'uniq_caution_casier_actif', columns: ['casier_actif'])]
#[ApiResource(
    shortName: 'CautionCasier',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'piscine.lire')"),
        new Get(security: "is_granted('PERM', 'piscine.lire')"),
    ],
    normalizationContext: ['groups' => ['caution:read']],
)]
class CautionCasier
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['caution:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Casier::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['caution:read'])]
    private ?Casier $casier = null;

    /** Dénormalisation de `casier` quand le statut n'est pas `liberee`, NULL sinon (unicité). */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $casierActif = null;

    #[ORM\Column(type: 'decimal', precision: 6, scale: 2)]
    #[Assert\PositiveOrZero]
    #[Groups(['caution:read'])]
    private string $montant = '0.00';

    #[ORM\Column(length: 10, enumType: StatutCaution::class, options: ['default' => 'encaissee'])]
    #[Groups(['caution:read'])]
    private StatutCaution $statut = StatutCaution::Encaissee;

    #[ORM\Column(length: 30, nullable: true)]
    #[Groups(['caution:read'])]
    private ?string $moyenEncaissement = null;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['caution:read'])]
    private ?Uuid $regieMouvementRef = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['caution:read'])]
    private ?\DateTimeImmutable $dateEncaissement = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['caution:read'])]
    private ?\DateTimeImmutable $dateLiberation = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCasier(): ?Casier
    {
        return $this->casier;
    }

    public function setCasier(?Casier $casier): self
    {
        $this->casier = $casier;
        $this->synchroniserCasierActif();

        return $this;
    }

    public function getCasierActif(): ?Uuid
    {
        return $this->casierActif;
    }

    public function getMontant(): string
    {
        return $this->montant;
    }

    public function setMontant(string $montant): self
    {
        $this->montant = $montant;

        return $this;
    }

    public function getStatut(): StatutCaution
    {
        return $this->statut;
    }

    public function setStatut(StatutCaution $statut): self
    {
        $this->statut = $statut;
        $this->synchroniserCasierActif();

        return $this;
    }

    private function synchroniserCasierActif(): void
    {
        $this->casierActif = $this->statut !== StatutCaution::Liberee ? $this->casier?->getId() : null;
    }

    public function getMoyenEncaissement(): ?string
    {
        return $this->moyenEncaissement;
    }

    public function setMoyenEncaissement(?string $moyenEncaissement): self
    {
        $this->moyenEncaissement = $moyenEncaissement;

        return $this;
    }

    public function getRegieMouvementRef(): ?Uuid
    {
        return $this->regieMouvementRef;
    }

    public function setRegieMouvementRef(?Uuid $regieMouvementRef): self
    {
        $this->regieMouvementRef = $regieMouvementRef;

        return $this;
    }

    public function getDateEncaissement(): ?\DateTimeImmutable
    {
        return $this->dateEncaissement;
    }

    public function setDateEncaissement(?\DateTimeImmutable $dateEncaissement): self
    {
        $this->dateEncaissement = $dateEncaissement;

        return $this;
    }

    public function getDateLiberation(): ?\DateTimeImmutable
    {
        return $this->dateLiberation;
    }

    public function setDateLiberation(?\DateTimeImmutable $dateLiberation): self
    {
        $this->dateLiberation = $dateLiberation;

        return $this;
    }

    public function getEtablissement(): ?Etablissement
    {
        return $this->casier?->getEtablissement();
    }
}
