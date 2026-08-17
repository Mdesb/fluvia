<?php

declare(strict_types=1);

namespace App\Caution\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Caution\Enum\StatutCaution;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Caution générique (module socle `App\Caution`, refactor du patron dépôt/consignation dupliqué par
 * `App\Piscine\Entity\CautionCasier`, `App\Padel\Entity\CautionMateriel` et
 * `App\Patinoire\Entity\CautionLocationPatins`). La cible restituée/retenue n'est **pas** référencée
 * par une association Doctrine vers une verticale (`App\Caution` ne dépend d'aucune verticale) : elle
 * est désignée par le couple opaque `typeCible`/`referenceCible` — même patron que
 * `App\Recouvrement\Entity\IncidentImpaye.typeRedevable/referenceRedevable`. `regieMouvementRef` est
 * une référence logique non-FK vers un mouvement de régie (module M6/M2, non câblé dans ce lot — même
 * hypothèse documentée que `CautionCasier.regieMouvementRef`).
 *
 * Chaque verticale garde une caution *active* unique par cible : garanti par la colonne dénormalisée
 * `referenceCibleActive` (= `referenceCible` tant que le statut n'est pas `restituee`, NULL sinon)
 * sous contrainte unique composite avec `typeCible` — même technique que
 * `CautionCasier.casierActif`/`Appairage.supportActif` (L3).
 */
#[ORM\Entity]
#[ORM\Table(name: 'caution_caution')]
#[ORM\UniqueConstraint(name: 'uniq_caution_cible_active', columns: ['type_cible', 'reference_cible_active'])]
#[ORM\Index(columns: ['type_cible', 'reference_cible'], name: 'idx_caution_cible')]
#[ApiResource(
    shortName: 'Caution',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'caution.piloter')"),
        new Get(security: "is_granted('PERM', 'caution.piloter')"),
    ],
    normalizationContext: ['groups' => ['caution:read']],
)]
class Caution
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['caution:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['caution:read'])]
    private ?Etablissement $etablissement = null;

    /** Type de cible porté par la verticale (ex. `piscine.casier`, `padel.materiel`, `patinoire.patins`). */
    #[ORM\Column(length: 40)]
    #[Assert\NotBlank]
    #[Groups(['caution:read'])]
    private string $typeCible = '';

    /** Identifiant opaque de la cible côté verticale (ex. l'UUID d'un `Casier`), non une FK réelle. */
    #[ORM\Column(length: 36)]
    #[Assert\NotBlank]
    #[Groups(['caution:read'])]
    private string $referenceCible = '';

    /** Dénormalisation de `referenceCible` quand le statut n'est pas `restituee`, NULL sinon (unicité). */
    #[ORM\Column(length: 36, nullable: true)]
    private ?string $referenceCibleActive = null;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    #[Groups(['caution:read'])]
    private int $montantCentimes = 0;

    #[ORM\Column(nullable: true)]
    #[Groups(['caution:read'])]
    private ?int $montantRetenuCentimes = null;

    #[ORM\Column(length: 17, enumType: StatutCaution::class, options: ['default' => 'consignee'])]
    #[Groups(['caution:read'])]
    private StatutCaution $statut = StatutCaution::Consignee;

    #[ORM\Column(length: 30, nullable: true)]
    #[Groups(['caution:read'])]
    private ?string $moyenEncaissement = null;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['caution:read'])]
    private ?Uuid $regieMouvementRef = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['caution:read'])]
    private ?\DateTimeImmutable $dateConsignation = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['caution:read'])]
    private ?\DateTimeImmutable $dateRestitution = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getTypeCible(): string
    {
        return $this->typeCible;
    }

    public function setTypeCible(string $typeCible): self
    {
        $this->typeCible = $typeCible;

        return $this;
    }

    public function getReferenceCible(): string
    {
        return $this->referenceCible;
    }

    public function setReferenceCible(string $referenceCible): self
    {
        $this->referenceCible = $referenceCible;
        $this->synchroniserCibleActive();

        return $this;
    }

    public function getReferenceCibleActive(): ?string
    {
        return $this->referenceCibleActive;
    }

    public function getMontantCentimes(): int
    {
        return $this->montantCentimes;
    }

    public function setMontantCentimes(int $montantCentimes): self
    {
        $this->montantCentimes = $montantCentimes;

        return $this;
    }

    /** Représentation décimale (« 10.00 ») pour compatibilité avec les champs `montant` des verticales. */
    public function getMontantDecimal(): string
    {
        return self::centimesVersDecimal($this->montantCentimes);
    }

    public function getMontantRetenuCentimes(): ?int
    {
        return $this->montantRetenuCentimes;
    }

    public function setMontantRetenuCentimes(?int $montantRetenuCentimes): self
    {
        $this->montantRetenuCentimes = $montantRetenuCentimes;

        return $this;
    }

    public function getMontantRetenuDecimal(): ?string
    {
        return $this->montantRetenuCentimes !== null ? self::centimesVersDecimal($this->montantRetenuCentimes) : null;
    }

    public function getStatut(): StatutCaution
    {
        return $this->statut;
    }

    public function setStatut(StatutCaution $statut): self
    {
        $this->statut = $statut;
        $this->synchroniserCibleActive();

        return $this;
    }

    private function synchroniserCibleActive(): void
    {
        $this->referenceCibleActive = ($this->statut !== StatutCaution::Restituee && $this->referenceCible !== '')
            ? $this->referenceCible
            : null;
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

    public function getDateConsignation(): ?\DateTimeImmutable
    {
        return $this->dateConsignation;
    }

    public function setDateConsignation(?\DateTimeImmutable $dateConsignation): self
    {
        $this->dateConsignation = $dateConsignation;

        return $this;
    }

    public function getDateRestitution(): ?\DateTimeImmutable
    {
        return $this->dateRestitution;
    }

    public function setDateRestitution(?\DateTimeImmutable $dateRestitution): self
    {
        $this->dateRestitution = $dateRestitution;

        return $this;
    }

    public static function centimesVersDecimal(int $centimes): string
    {
        return number_format($centimes / 100, 2, '.', '');
    }

    /** ⚠ Suppose un montant positif ou nul (garanti par `Assert\PositiveOrZero`/decimal à 2 décimales). */
    public static function decimalVersCentimes(string $montant): int
    {
        $parties = explode('.', trim($montant), 2);
        $entier = (int) $parties[0];
        $decimales = (int) str_pad(substr($parties[1] ?? '0', 0, 2), 2, '0');

        return $entier * 100 + $decimales;
    }
}
