<?php

declare(strict_types=1);

namespace App\Padel\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Padel\Enum\StatutRetourMateriel;
use App\Padel\State\LouerMaterielProcessor;
use App\Padel\State\RetournerMaterielProcessor;
use App\Reservation\Entity\Reservation;
use App\Vente\Entity\Vente;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Location de matériel (raquette/balles) rattachée à une réservation (US-PADEL-08). `article` est une
 * référence logique vers `App\Offre\Entity\Produit` (type location), non une FK réelle (même patron
 * que `ParametragePadel.produitTerrainRef`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'padel_location_materiel')]
#[ApiResource(
    shortName: 'PadelLocationMateriel',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'padel.lire')"),
        new Get(security: "is_granted('PERM', 'padel.lire')"),
        // Corps : { "reservation": iri|uuid, "article": uuid, "quantite": int, "caution"?: decimal }
        // (flat plutôt que nested {reservationId} : `Reservation` appartient à App\Reservation, non modifiable).
        new Post(
            uriTemplate: '/padel/locations',
            read: false,
            security: "is_granted('PERM', 'padel.materiel_gerer') or is_granted('PERM', 'padel.reserver_soi')",
            processor: LouerMaterielProcessor::class,
        ),
        new Post(
            uriTemplate: '/padel/locations/{id}/retour',
            read: true,
            input: false,
            security: "is_granted('PERM', 'padel.materiel_gerer')",
            processor: RetournerMaterielProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['location:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['reservation' => 'exact', 'statutRetour' => 'exact'])]
class LocationMateriel
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['location:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['location:read'])]
    private ?Reservation $reservation = null;

    #[ORM\Column(type: UuidType::NAME)]
    #[Groups(['location:read'])]
    private ?Uuid $article = null;

    #[ORM\Column(type: 'smallint')]
    #[Groups(['location:read'])]
    private int $quantite = 1;

    #[ORM\ManyToOne(targetEntity: Vente::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['location:read'])]
    private ?Vente $venteRattachee = null;

    #[ORM\Column(length: 9, enumType: StatutRetourMateriel::class, options: ['default' => 'en_cours'])]
    #[Groups(['location:read'])]
    private StatutRetourMateriel $statutRetour = StatutRetourMateriel::EnCours;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getReservation(): ?Reservation
    {
        return $this->reservation;
    }

    public function setReservation(?Reservation $reservation): self
    {
        $this->reservation = $reservation;

        return $this;
    }

    public function getArticle(): ?Uuid
    {
        return $this->article;
    }

    public function setArticle(?Uuid $article): self
    {
        $this->article = $article;

        return $this;
    }

    public function getQuantite(): int
    {
        return $this->quantite;
    }

    public function setQuantite(int $quantite): self
    {
        $this->quantite = $quantite;

        return $this;
    }

    public function getVenteRattachee(): ?Vente
    {
        return $this->venteRattachee;
    }

    public function setVenteRattachee(?Vente $venteRattachee): self
    {
        $this->venteRattachee = $venteRattachee;

        return $this;
    }

    public function getStatutRetour(): StatutRetourMateriel
    {
        return $this->statutRetour;
    }

    public function setStatutRetour(StatutRetourMateriel $statutRetour): self
    {
        $this->statutRetour = $statutRetour;

        return $this;
    }
}
