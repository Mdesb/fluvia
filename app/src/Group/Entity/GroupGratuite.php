<?php

declare(strict_types=1);

namespace App\Group\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use App\Group\State\RevokeGratuiteProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Gratuités accordées à une réservation de groupe depuis un contingent : `quantite` entrées gratuites,
 * qui sortent du décompte payant du devis (une entrée gratuite ne se facture pas).
 *
 * Créée par l'octroi (`POST /group/bookings/{id}/grant-gratuite`, pas de `Post` direct) ; la
 * suppression (`RevokeGratuiteProcessor`) recrédite le contingent. Cloisonnée par sa réservation.
 */
#[ORM\Entity]
#[ORM\Table(name: 'group_gratuite')]
#[ApiResource(
    shortName: 'GroupGratuite',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'group.read')"),
        new Delete(security: "is_granted('PERM', 'group.manage')", processor: RevokeGratuiteProcessor::class),
    ],
    normalizationContext: ['groups' => ['gratuite:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['booking' => 'exact'])]
class GroupGratuite
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['gratuite:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: GroupBooking::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['gratuite:read'])]
    private ?GroupBooking $booking = null;

    #[ORM\ManyToOne(targetEntity: GroupGratuiteContingent::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['gratuite:read'])]
    private ?GroupGratuiteContingent $contingent = null;

    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    #[Groups(['gratuite:read'])]
    private int $quantite = 1;

    #[ORM\Column(length: 120, nullable: true)]
    #[Groups(['gratuite:read'])]
    private ?string $motif = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getBooking(): ?GroupBooking
    {
        return $this->booking;
    }

    public function setBooking(?GroupBooking $booking): self
    {
        $this->booking = $booking;

        return $this;
    }

    public function getContingent(): ?GroupGratuiteContingent
    {
        return $this->contingent;
    }

    public function setContingent(?GroupGratuiteContingent $contingent): self
    {
        $this->contingent = $contingent;

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

    public function getMotif(): ?string
    {
        return $this->motif;
    }

    public function setMotif(?string $motif): self
    {
        $this->motif = $motif;

        return $this;
    }
}
