<?php

declare(strict_types=1);

namespace App\Patinoire\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Acces\Entity\EspaceAcces;
use App\Organisation\Entity\Etablissement;
use App\Patinoire\Enum\TypeZonePatinoire;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Zone patinoire (glace ou gradins), spécialisation patinoire d'un `EspaceAcces` L3 (RG-PAT-02,
 * US-PATIN-08, patron `App\Piscine\Entity\Poss`) : **délègue** seuil/mode/jauge/pré-alerte à
 * l'`EspaceAcces` référencé, sans dupliquer ni modifier le moteur générique de jauge FMI (§4.7,
 * « la patinoire ne fait que configurer deux espaces distincts »).
 */
#[ORM\Entity]
#[ORM\Table(name: 'patin_zone')]
#[ORM\UniqueConstraint(name: 'uniq_zone_patinoire_espace_acces', columns: ['espace_acces_id'])]
#[UniqueEntity(fields: ['espaceAcces'], message: 'Cet espace d\'accès porte déjà une zone patinoire.')]
#[ApiResource(
    shortName: 'PatinoireZonePatinoire',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'patinoire.lire') or is_granted('PERM', 'acces.lire')"),
        new Get(security: "is_granted('PERM', 'patinoire.lire') or is_granted('PERM', 'acces.lire')"),
        new Post(security: "is_granted('PERM', 'patinoire.configurer')"),
        new Patch(security: "is_granted('PERM', 'patinoire.configurer')"),
    ],
    normalizationContext: ['groups' => ['zone_patinoire:read']],
    denormalizationContext: ['groups' => ['zone_patinoire:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['typeZone' => 'exact', 'espaceAcces' => 'exact', 'etablissement' => 'exact'])]
class ZonePatinoire
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['zone_patinoire:read'])]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: EspaceAcces::class)]
    #[ORM\JoinColumn(name: 'espace_acces_id', nullable: false)]
    #[Assert\NotNull(message: 'Un espace d\'accès L3 est requis (délégation seuil/mode/jauge).')]
    #[Groups(['zone_patinoire:read', 'zone_patinoire:write'])]
    private ?EspaceAcces $espaceAcces = null;

    #[ORM\Column(length: 8, enumType: TypeZonePatinoire::class)]
    #[Assert\NotNull]
    #[Groups(['zone_patinoire:read', 'zone_patinoire:write'])]
    private ?TypeZonePatinoire $typeZone = null;

    /** Dénormalisation de `espaceAcces.etablissement` (cloisonnement, patron `Poss`). */
    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['zone_patinoire:read'])]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEspaceAcces(): ?EspaceAcces
    {
        return $this->espaceAcces;
    }

    public function setEspaceAcces(?EspaceAcces $espaceAcces): self
    {
        $this->espaceAcces = $espaceAcces;
        if ($espaceAcces !== null) {
            $this->etablissement = $espaceAcces->getEtablissement();
        }

        return $this;
    }

    public function getTypeZone(): ?TypeZonePatinoire
    {
        return $this->typeZone;
    }

    public function setTypeZone(?TypeZonePatinoire $typeZone): self
    {
        $this->typeZone = $typeZone;

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
