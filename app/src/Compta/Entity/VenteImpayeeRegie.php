<?php

declare(strict_types=1);

namespace App\Compta\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Compta\State\MarquerImpayeeRegieProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Marquage anti-double-comptabilisation (RG-M6-09) : une recette de régie déjà transmise via
 * PES/régie est exclue/signalée dans l'agrégat e-reporting.
 */
#[ORM\Entity]
#[ORM\Table(name: 'compta_vente_impayee_regie')]
#[ORM\UniqueConstraint(name: 'uniq_impayee_vente', columns: ['vente_origine'])]
#[ApiResource(
    shortName: 'VenteImpayeeRegie',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'compta.lire')"),
        new Get(security: "is_granted('PERM', 'compta.lire')"),
        new Post(
            uriTemplate: '/compta/ventes/{id}/marquer-impayee-regie',
            read: false,
            input: false,
            security: "is_granted('PERM', 'compta.gerer')",
            processor: MarquerImpayeeRegieProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['impaye:read']],
)]
class VenteImpayeeRegie
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['impaye:read'])]
    private Uuid $id;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['impaye:read'])]
    private Uuid $venteOrigine;

    #[ORM\Column(length: 255)]
    #[Groups(['impaye:read'])]
    private string $motif = '';

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['impaye:read'])]
    private \DateTimeImmutable $dateMarquage;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->venteOrigine = Uuid::v4();
        $this->dateMarquage = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getVenteOrigine(): Uuid
    {
        return $this->venteOrigine;
    }

    public function setVenteOrigine(Uuid $venteOrigine): self
    {
        $this->venteOrigine = $venteOrigine;

        return $this;
    }

    public function getMotif(): string
    {
        return $this->motif;
    }

    public function setMotif(string $motif): self
    {
        $this->motif = $motif;

        return $this;
    }

    public function getDateMarquage(): \DateTimeImmutable
    {
        return $this->dateMarquage;
    }
}
