<?php

declare(strict_types=1);

namespace App\Padel\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Padel\Enum\TypeTerrain;
use App\Padel\State\CreerTerrainProcessor;
use App\Padel\State\ForcerEclairageManuelProcessor;
use App\Padel\State\ReserverTerrainProcessor;
use App\Reservation\Entity\Ressource;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Terrain de padel : overlay 1:1 sur une `Ressource` du socle Réservation (`codeType='terrain_padel'`,
 * décision structurante n°1 du plan). Ne porte que les champs absents du modèle générique.
 */
#[ORM\Entity]
#[ORM\Table(name: 'padel_terrain')]
#[ApiResource(
    shortName: 'PadelTerrain',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'padel.lire')"),
        new Get(security: "is_granted('PERM', 'padel.lire')"),
        new Post(
            uriTemplate: '/padel/terrains',
            security: "is_granted('PERM', 'padel.gerer_terrain')",
            processor: CreerTerrainProcessor::class,
        ),
        new Patch(security: "is_granted('PERM', 'padel.gerer_terrain')"),
        // Réserve un créneau sur ce terrain (US-PADEL-01, CA-1/CA-2/CA-10) : {id} = identifiant du
        // terrain, output = l'overlay ReservationPadel créé (avec la Reservation socle sous-jacente).
        new Post(
            uriTemplate: '/padel/terrains/{id}/reservations',
            read: true,
            input: false,
            security: "is_granted('PERM', 'padel.reserver') or is_granted('PERM', 'padel.reserver_soi')",
            processor: ReserverTerrainProcessor::class,
            output: ReservationPadel::class,
            normalizationContext: ['groups' => ['reservation_padel:read']],
        ),
        // Repli manuel de l'éclairage (RG-PADEL-05, CA-11) : motif requis, tracé.
        new Post(
            uriTemplate: '/padel/terrains/{id}/eclairage/repli-manuel',
            read: true,
            input: false,
            security: "is_granted('PERM', 'padel.acces_forcer')",
            processor: ForcerEclairageManuelProcessor::class,
            output: EvenementEclairage::class,
            normalizationContext: ['groups' => ['evenement_eclairage:read']],
        ),
    ],
    normalizationContext: ['groups' => ['terrain:read']],
    denormalizationContext: ['groups' => ['terrain:write']],
)]
class TerrainPadel
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['terrain:read', 'reservation_padel:read', 'match_tournoi:read'])]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: Ressource::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    #[Groups(['terrain:read'])]
    private ?Ressource $ressource = null;

    #[ORM\Column(length: 10, enumType: TypeTerrain::class)]
    #[Groups(['terrain:read', 'terrain:write'])]
    private TypeTerrain $type = TypeTerrain::Indoor;

    /** @var list<int> ⊆ {60, 90} */
    #[ORM\Column]
    #[Groups(['terrain:read', 'terrain:write'])]
    private array $dureesAutoriseesMinutes = [60, 90];

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['terrain:read', 'terrain:write'])]
    private bool $actif = true;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRessource(): ?Ressource
    {
        return $this->ressource;
    }

    public function setRessource(?Ressource $ressource): self
    {
        $this->ressource = $ressource;

        return $this;
    }

    public function getType(): TypeTerrain
    {
        return $this->type;
    }

    public function setType(TypeTerrain $type): self
    {
        $this->type = $type;

        return $this;
    }

    /** @return list<int> */
    public function getDureesAutoriseesMinutes(): array
    {
        return $this->dureesAutoriseesMinutes;
    }

    /** @param list<int> $dureesAutoriseesMinutes */
    public function setDureesAutoriseesMinutes(array $dureesAutoriseesMinutes): self
    {
        $this->dureesAutoriseesMinutes = $dureesAutoriseesMinutes;

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

    #[Groups(['terrain:read'])]
    public function getEtablissement(): ?\App\Organisation\Entity\Etablissement
    {
        return $this->ressource?->getEtablissement();
    }
}
