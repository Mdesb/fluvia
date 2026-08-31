<?php

declare(strict_types=1);

namespace App\Piscine\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use App\Piscine\Enum\EtatCasier;
use App\Piscine\State\EstablishmentStampProcessor;
use App\Piscine\State\AttribuerCasierProcessor;
use App\Piscine\State\ForcerCasierProcessor;
use App\Piscine\State\LibererCasierProcessor;
use App\Piscine\State\RelancerCasierProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Casier connecté (US-L6-09, cahier §4). Attribution/libération liées au bracelet ; caution
 * consignée à l'attribution (`CautionCasier`) ; relance puis forçage administratif après délai.
 */
#[ORM\Entity]
#[ORM\Table(name: 'piscine_casier')]
#[ORM\UniqueConstraint(name: 'uniq_casier_etab_zone_numero', columns: ['etablissement_id', 'zone', 'numero'])]
#[ApiResource(
    shortName: 'Casier',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'piscine.lire')"),
        new Get(security: "is_granted('PERM', 'piscine.lire')"),
        new Post(security: "is_granted('PERM', 'piscine.configurer')", processor: EstablishmentStampProcessor::class),
        new Post(
            uriTemplate: '/piscine/casiers/{id}/attribuer',
            read: true,
            input: false,
            security: "is_granted('PERM', 'piscine.gerer_casier')",
            processor: AttribuerCasierProcessor::class,
        ),
        new Post(
            uriTemplate: '/piscine/casiers/{id}/liberer',
            read: true,
            input: false,
            security: "is_granted('PERM', 'piscine.gerer_casier')",
            processor: LibererCasierProcessor::class,
        ),
        new Post(
            uriTemplate: '/piscine/casiers/{id}/relancer',
            read: true,
            input: false,
            security: "is_granted('PERM', 'piscine.gerer_casier')",
            processor: RelancerCasierProcessor::class,
        ),
        new Post(
            uriTemplate: '/piscine/casiers/{id}/forcer',
            read: true,
            input: false,
            security: "is_granted('PERM', 'piscine.forcer_casier')",
            processor: ForcerCasierProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['casier:read']],
    denormalizationContext: ['groups' => ['casier:write']],
)]
class Casier
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['casier:read'])]
    private Uuid $id;

    #[ORM\Column(type: 'smallint')]
    #[Assert\Positive]
    #[Groups(['casier:read', 'casier:write'])]
    private int $numero = 1;

    #[ORM\Column(length: 60)]
    #[Assert\NotBlank]
    #[Groups(['casier:read', 'casier:write'])]
    private string $zone = '';

    #[ORM\Column(length: 10, enumType: EtatCasier::class, options: ['default' => 'libre'])]
    #[Groups(['casier:read'])]
    private EtatCasier $etat = EtatCasier::Libre;

    #[ORM\ManyToOne(targetEntity: BraceletEtanche::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['casier:read'])]
    private ?BraceletEtanche $bracelet = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['casier:read'])]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getNumero(): int
    {
        return $this->numero;
    }

    public function setNumero(int $numero): self
    {
        $this->numero = $numero;

        return $this;
    }

    public function getZone(): string
    {
        return $this->zone;
    }

    public function setZone(string $zone): self
    {
        $this->zone = $zone;

        return $this;
    }

    public function getEtat(): EtatCasier
    {
        return $this->etat;
    }

    public function setEtat(EtatCasier $etat): self
    {
        $this->etat = $etat;

        return $this;
    }

    public function getBracelet(): ?BraceletEtanche
    {
        return $this->bracelet;
    }

    public function setBracelet(?BraceletEtanche $bracelet): self
    {
        $this->bracelet = $bracelet;

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
