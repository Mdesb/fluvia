<?php

declare(strict_types=1);

namespace App\Group\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Group\State\EstablishmentStampProcessor;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Contingent de gratuités **transverse** (arbitrage Maxime, 08/09) : une enveloppe d'entrées gratuites
 * qu'un établissement accorde à des groupes — repris du musée (`ContingentGratuite`) mais réutilisable
 * par tous les métiers. On accorde des gratuités à une réservation depuis un contingent
 * (`POST /group/bookings/{id}/grant-gratuite`) ; le décompte tenu par `consomme` empêche de dépasser.
 *
 * D41 — `etablissement` estampillé côté serveur.
 */
#[ORM\Entity]
#[ORM\Table(name: 'group_gratuite_contingent')]
#[ApiResource(
    shortName: 'GroupGratuiteContingent',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'group.read')"),
        new Post(security: "is_granted('PERM', 'group.manage')", processor: EstablishmentStampProcessor::class),
        new Patch(security: "is_granted('PERM', 'group.manage')"),
        new Delete(security: "is_granted('PERM', 'group.manage')"),
    ],
    normalizationContext: ['groups' => ['gratuite_contingent:read']],
    denormalizationContext: ['groups' => ['gratuite_contingent:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['actif' => 'exact'])]
class GroupGratuiteContingent
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['gratuite_contingent:read', 'gratuite:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['gratuite_contingent:read'])]
    private ?Etablissement $etablissement = null;

    #[ORM\Column(length: 160)]
    #[Assert\NotBlank]
    #[Groups(['gratuite_contingent:read', 'gratuite_contingent:write', 'gratuite:read'])]
    private string $label = '';

    #[ORM\Column(length: 120, nullable: true)]
    #[Groups(['gratuite_contingent:read', 'gratuite_contingent:write'])]
    private ?string $motif = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    #[Groups(['gratuite_contingent:read', 'gratuite_contingent:write'])]
    private int $quota = 0;

    /** Décompté à chaque octroi, recrédité à la révocation : jamais écrit directement par le client. */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Groups(['gratuite_contingent:read'])]
    private int $consomme = 0;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['gratuite_contingent:read', 'gratuite_contingent:write'])]
    private bool $actif = true;

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

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;

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

    public function getQuota(): int
    {
        return $this->quota;
    }

    public function setQuota(int $quota): self
    {
        $this->quota = $quota;

        return $this;
    }

    public function getConsomme(): int
    {
        return $this->consomme;
    }

    public function setConsomme(int $consomme): self
    {
        $this->consomme = max(0, $consomme);

        return $this;
    }

    #[Groups(['gratuite_contingent:read'])]
    public function getPlacesRestantes(): int
    {
        return max(0, $this->quota - $this->consomme);
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
