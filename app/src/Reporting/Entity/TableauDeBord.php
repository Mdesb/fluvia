<?php

declare(strict_types=1);

namespace App\Reporting\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Reporting\Entity\Trait\RattachementNiveauInterface;
use App\Reporting\State\TableauDeBordProcessor;
use App\Reporting\Entity\Trait\RattachementNiveauTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

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
        // Le rattachement ne peut pas passer par la deserialisation : les quatre proprietes du
        // trait sont privees et sans setter. Voir TableauDeBordProcessor pour la mesure.
        new Post(
            security: "is_granted('PERM', 'reporting.configurer')",
            input: false,
            processor: TableauDeBordProcessor::class,
        ),
        new Patch(
            security: "is_granted('PERM', 'reporting.configurer')",
            input: false,
            processor: TableauDeBordProcessor::class,
        ),
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

    /**
     * Le nom. ⚠ Son `Assert\NotBlank` a été retiré le 05/09 : `input: false` fait que la
     * validation ne voit plus jamais la charge du client. L’exigence est dans le processeur,
     * qui refuse aussi un nom présent mais vide — ce que le `NotBlank` ne faisait plus.
     */
    #[ORM\Column(length: 160)]
    #[Groups(['tdb:read', 'tdb:write', 'rapport:read'])]
    private string $nom = '';

    /**
     * @var Collection<int, Indicateur> RG-M7-06 : au moins un indicateur.
     *
     * ⚠ LA RÈGLE N’EST PLUS ICI. Elle tenait dans un `Assert\Count(min: 1)`, qui ne s’exécutait
     * qu’à la désérialisation ; depuis que `Post` et `Patch` sont `input: false`, aucune
     * validation ne tourne sur cette ressource. `TableauDeBordProcessor` la rejoue, et le
     * fait sur l’état FINAL de la composition — donc aussi quand la requête ne parle pas
     * des indicateurs.
     */
    #[ORM\ManyToMany(targetEntity: Indicateur::class)]
    #[ORM\JoinTable(name: 'report_tableau_de_bord_indicateur')]
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
