<?php

declare(strict_types=1);

namespace App\Reporting\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Reporting\Entity\Trait\RattachementNiveauInterface;
use App\Reporting\Entity\Trait\RattachementNiveauTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Modèle de tableau de bord (§1.6 plan-reporting.md) : composition d'indicateurs référencée par un
 * `RapportPlanifie` (RG-M7-06). Jamais supprimé (cas limite spec §7, même patron qu'`Indicateur`) :
 * pas de `Delete` exposé, seule `Patch(actif=false)` désactive — écart documenté vs « CRUD complet »
 * du §3 du plan, retenu par cohérence avec le patron « référentiel non supprimable » déjà établi.
 */
#[ORM\Entity]
#[ORM\Table(name: 'report_tableau_de_bord')]
#[ApiResource(
    shortName: 'TableauDeBord',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'reporting.lire')"),
        new Get(security: "is_granted('PERM', 'reporting.lire')"),
        new Post(security: "is_granted('PERM', 'reporting.configurer')"),
        new Patch(security: "is_granted('PERM', 'reporting.configurer')"),
    ],
    normalizationContext: ['groups' => ['tdb:read']],
    denormalizationContext: ['groups' => ['tdb:write']],
)]
class TableauDeBord implements RattachementNiveauInterface
{
    use RattachementNiveauTrait;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['tdb:read', 'rapport:read'])]
    private Uuid $id;

    #[ORM\Column(length: 160)]
    #[Assert\NotBlank]
    #[Groups(['tdb:read', 'tdb:write', 'rapport:read'])]
    private string $nom = '';

    /** @var Collection<int, Indicateur> RG-M7-06 : ≥ 1 (validé nativement, groupe tdb:write). */
    #[ORM\ManyToMany(targetEntity: Indicateur::class)]
    #[ORM\JoinTable(name: 'report_tableau_de_bord_indicateur')]
    #[Assert\Count(min: 1, minMessage: 'Un tableau de bord doit référencer au moins un indicateur.')]
    #[Groups(['tdb:read', 'tdb:write'])]
    private Collection $indicateurs;

    /** @var array<string, mixed>|null Disposition des widgets, opaque côté back. */
    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['tdb:read', 'tdb:write'])]
    private ?array $miseEnPage = null;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['tdb:read', 'tdb:write'])]
    private bool $actif = true;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->indicateurs = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): self
    {
        $this->nom = $nom;

        return $this;
    }

    /** @return Collection<int, Indicateur> */
    public function getIndicateurs(): Collection
    {
        return $this->indicateurs;
    }

    public function addIndicateur(Indicateur $indicateur): self
    {
        if (!$this->indicateurs->contains($indicateur)) {
            $this->indicateurs->add($indicateur);
        }

        return $this;
    }

    public function removeIndicateur(Indicateur $indicateur): self
    {
        $this->indicateurs->removeElement($indicateur);

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getMiseEnPage(): ?array
    {
        return $this->miseEnPage;
    }

    /** @param array<string, mixed>|null $miseEnPage */
    public function setMiseEnPage(?array $miseEnPage): self
    {
        $this->miseEnPage = $miseEnPage;

        return $this;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): self
    {
        $this->actif = $actif;

        return $this;
    }
}
