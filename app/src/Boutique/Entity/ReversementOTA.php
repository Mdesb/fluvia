<?php

declare(strict_types=1);

namespace App\Boutique\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Boutique\Enum\StatutReversementOTA;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Reversement à un partenaire OTA (RG-M3-09) : tarif net + commission sur les ventes OTA confirmées
 * de la période.
 */
#[ORM\Entity]
#[ORM\Table(name: 'bou_reversement_ota')]
#[ApiResource(
    shortName: 'ReversementOta',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'boutique.lire')"),
        new Get(security: "is_granted('PERM', 'boutique.lire')"),
        new Post(security: "is_granted('PERM', 'boutique.gerer_connecteur_ota')"),
        new Patch(security: "is_granted('PERM', 'boutique.gerer_connecteur_ota')"),
    ],
    normalizationContext: ['groups' => ['reversement_ota:read']],
    denormalizationContext: ['groups' => ['reversement_ota:write']],
)]
class ReversementOTA
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['reversement_ota:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: PartenaireOTA::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['reversement_ota:read', 'reversement_ota:write'])]
    private ?PartenaireOTA $partenaire = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['reversement_ota:read', 'reversement_ota:write'])]
    private ?\DateTimeImmutable $periodeDebut = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['reversement_ota:read', 'reversement_ota:write'])]
    private ?\DateTimeImmutable $periodeFin = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    #[Groups(['reversement_ota:read'])]
    private string $montant = '0.00';

    #[ORM\Column(length: 10, enumType: StatutReversementOTA::class, options: ['default' => 'a_verser'])]
    #[Groups(['reversement_ota:read'])]
    private StatutReversementOTA $statut = StatutReversementOTA::AVerser;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPartenaire(): ?PartenaireOTA
    {
        return $this->partenaire;
    }

    public function setPartenaire(?PartenaireOTA $partenaire): self
    {
        $this->partenaire = $partenaire;
        $this->etablissement = $partenaire?->getEtablissement();

        return $this;
    }

    public function getPeriodeDebut(): ?\DateTimeImmutable
    {
        return $this->periodeDebut;
    }

    public function setPeriodeDebut(?\DateTimeImmutable $periodeDebut): self
    {
        $this->periodeDebut = $periodeDebut;

        return $this;
    }

    public function getPeriodeFin(): ?\DateTimeImmutable
    {
        return $this->periodeFin;
    }

    public function setPeriodeFin(?\DateTimeImmutable $periodeFin): self
    {
        $this->periodeFin = $periodeFin;

        return $this;
    }

    public function getMontant(): string
    {
        return $this->montant;
    }

    public function setMontant(string $montant): self
    {
        $this->montant = $montant;

        return $this;
    }

    public function getStatut(): StatutReversementOTA
    {
        return $this->statut;
    }

    public function setStatut(StatutReversementOTA $statut): self
    {
        $this->statut = $statut;

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
