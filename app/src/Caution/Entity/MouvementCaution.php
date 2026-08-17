<?php

declare(strict_types=1);

namespace App\Caution\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Caution\Enum\TypeMouvementCaution;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Journal append-only d'une caution générique (refactor du patron dupliqué par
 * `App\Piscine\Entity\ForcageCasier`/`RelanceCasier` et `App\Patinoire\Entity\RetenueCaution`) : une
 * ligne par événement (consignation, restitution, retenue proposée puis validée, relance, forçage
 * administratif). Une retenue est créée *proposée* (`mouvementRegieRef` NULL) puis **validée**
 * séparément (`App\Caution\Service\GestionCaution::validerRetenue()`), même patron en deux temps que
 * `App\Patinoire\Entity\RetenueCaution`. `forcee=true` si le montant validé diffère du montant par
 * défaut proposé par la grille — garde-fou RG-SOCLE-07.
 */
#[ORM\Entity]
#[ORM\Table(name: 'caution_mouvement')]
#[ApiResource(
    shortName: 'CautionMouvement',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'caution.piloter')"),
        new Get(security: "is_granted('PERM', 'caution.piloter')"),
    ],
    normalizationContext: ['groups' => ['caution_mouvement:read']],
)]
class MouvementCaution
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['caution_mouvement:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Caution::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['caution_mouvement:read'])]
    private ?Caution $caution = null;

    #[ORM\Column(length: 12, enumType: TypeMouvementCaution::class)]
    #[Groups(['caution_mouvement:read'])]
    private TypeMouvementCaution $type;

    #[ORM\Column(nullable: true)]
    #[Groups(['caution_mouvement:read'])]
    private ?int $montantCentimes = null;

    #[ORM\ManyToOne(targetEntity: GrilleRetenue::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['caution_mouvement:read'])]
    private ?GrilleRetenue $grilleAppliquee = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['caution_mouvement:read'])]
    private ?Utilisateur $agent = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['caution_mouvement:read'])]
    private ?string $motif = null;

    /** Délai (jours) avant forçage autorisé — renseigné uniquement pour un mouvement de type `relance`. */
    #[ORM\Column(type: 'smallint', nullable: true)]
    #[Groups(['caution_mouvement:read'])]
    private ?int $delaiForcageJours = null;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['caution_mouvement:read'])]
    private bool $forcee = false;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['caution_mouvement:read'])]
    private ?Uuid $mouvementRegieRef = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['caution_mouvement:read'])]
    private \DateTimeImmutable $horodatage;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->horodatage = new \DateTimeImmutable();
        $this->type = TypeMouvementCaution::Consignation;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCaution(): ?Caution
    {
        return $this->caution;
    }

    public function setCaution(?Caution $caution): self
    {
        $this->caution = $caution;

        return $this;
    }

    public function getType(): TypeMouvementCaution
    {
        return $this->type;
    }

    public function setType(TypeMouvementCaution $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getMontantCentimes(): ?int
    {
        return $this->montantCentimes;
    }

    public function setMontantCentimes(?int $montantCentimes): self
    {
        $this->montantCentimes = $montantCentimes;

        return $this;
    }

    public function getMontantDecimal(): ?string
    {
        return $this->montantCentimes !== null ? Caution::centimesVersDecimal($this->montantCentimes) : null;
    }

    public function getGrilleAppliquee(): ?GrilleRetenue
    {
        return $this->grilleAppliquee;
    }

    public function setGrilleAppliquee(?GrilleRetenue $grilleAppliquee): self
    {
        $this->grilleAppliquee = $grilleAppliquee;

        return $this;
    }

    public function getAgent(): ?Utilisateur
    {
        return $this->agent;
    }

    public function setAgent(?Utilisateur $agent): self
    {
        $this->agent = $agent;

        return $this;
    }

    public function getMotif(): ?string
    {
        return $this->motif;
    }

    public function setMotif(?string $motif): self
    {
        $this->motif = $motif;

        return $this;
    }

    public function getDelaiForcageJours(): ?int
    {
        return $this->delaiForcageJours;
    }

    public function setDelaiForcageJours(?int $delaiForcageJours): self
    {
        $this->delaiForcageJours = $delaiForcageJours;

        return $this;
    }

    public function isForcee(): bool
    {
        return $this->forcee;
    }

    public function setForcee(bool $forcee): self
    {
        $this->forcee = $forcee;

        return $this;
    }

    public function getMouvementRegieRef(): ?Uuid
    {
        return $this->mouvementRegieRef;
    }

    public function setMouvementRegieRef(?Uuid $mouvementRegieRef): self
    {
        $this->mouvementRegieRef = $mouvementRegieRef;

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

    /** Une retenue est validée dès qu'une trace comptable régie lui est associée (patron `RetenueCaution::estValidee()`). */
    public function estValidee(): bool
    {
        return $this->mouvementRegieRef !== null;
    }

    /** Vrai si le délai de forçage (mouvement de type `relance`) est dépassé à la date donnée. */
    public function forcageAutoriseA(\DateTimeImmutable $date): bool
    {
        if ($this->delaiForcageJours === null) {
            return false;
        }

        return $date >= $this->horodatage->modify(sprintf('+%d days', $this->delaiForcageJours));
    }
}
