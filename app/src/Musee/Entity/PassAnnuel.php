<?php

declare(strict_types=1);

namespace App\Musee\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Acces\Entity\Support;
use App\Crm\Entity\Beneficiaire;
use App\Offre\Entity\Formule;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Pass annuel « Amis du musée » (US-MUSEE-10, RG-MUS-05) : spécialisation musée d'une `Formule`
 * d'abonnement (M1, **réutilisée** : droit d'accès illimité, renouvellement). Accès **illimité** à la
 * collection permanente sans nouveau paiement, mais **reste soumis** au choix d'un créneau pour toute
 * exposition à jauge (RG-MUS-01) — aucune exception au parcours de créneau.
 */
#[ORM\Entity]
#[ORM\Table(name: 'musee_pass_annuel')]
#[ORM\UniqueConstraint(name: 'uniq_pass_annuel_formule', columns: ['formule_id'])]
#[ORM\UniqueConstraint(name: 'uniq_pass_annuel_support', columns: ['support_id'])]
#[ApiResource(
    shortName: 'MuseePassAnnuel',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'musee.lire')"),
        new Get(security: "is_granted('PERM', 'musee.lire')"),
        new Post(security: "is_granted('PERM', 'musee.gerer_pass')"),
        new Patch(security: "is_granted('PERM', 'musee.gerer_pass')"),
    ],
    normalizationContext: ['groups' => ['pass:read']],
    denormalizationContext: ['groups' => ['pass:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['etablissement' => 'exact', 'adherent' => 'exact'])]
class PassAnnuel
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['pass:read'])]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: Formule::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    #[Assert\NotNull]
    #[Groups(['pass:read', 'pass:write'])]
    private ?Formule $formule = null;

    #[ORM\ManyToOne(targetEntity: Beneficiaire::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['pass:read', 'pass:write'])]
    private ?Beneficiaire $adherent = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Assert\NotNull]
    #[Groups(['pass:read', 'pass:write'])]
    private ?\DateTimeImmutable $echeance = null;

    /** @var list<string> ⊂ {coupe_file, tarif_preferentiel}. */
    #[ORM\Column(options: ['default' => '[]'])]
    #[Groups(['pass:read', 'pass:write'])]
    private array $avantages = [];

    #[ORM\OneToOne(targetEntity: Support::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    #[Assert\NotNull]
    #[Groups(['pass:read', 'pass:write'])]
    private ?Support $support = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['pass:read'])]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getFormule(): ?Formule
    {
        return $this->formule;
    }

    public function setFormule(?Formule $formule): self
    {
        $this->formule = $formule;

        return $this;
    }

    public function getAdherent(): ?Beneficiaire
    {
        return $this->adherent;
    }

    public function setAdherent(?Beneficiaire $adherent): self
    {
        $this->adherent = $adherent;

        return $this;
    }

    public function getEcheance(): ?\DateTimeImmutable
    {
        return $this->echeance;
    }

    public function setEcheance(?\DateTimeImmutable $echeance): self
    {
        $this->echeance = $echeance;

        return $this;
    }

    /** @return list<string> */
    public function getAvantages(): array
    {
        return $this->avantages;
    }

    /** @param list<string> $avantages */
    public function setAvantages(array $avantages): self
    {
        $this->avantages = array_values($avantages);

        return $this;
    }

    public function getSupport(): ?Support
    {
        return $this->support;
    }

    public function setSupport(?Support $support): self
    {
        $this->support = $support;

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

    public function estValide(\DateTimeImmutable $date): bool
    {
        return $this->echeance !== null && $date <= $this->echeance;
    }
}
