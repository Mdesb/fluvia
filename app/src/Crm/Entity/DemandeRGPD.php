<?php

declare(strict_types=1);

namespace App\Crm\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Crm\Enum\StatutDemandeRgpd;
use App\Crm\Enum\TypeDemandeRgpd;
use App\Crm\State\TraiterDemandeRgpdProcessor;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Demande d'exercice du droit à l'effacement / anonymisation (RG-M4-09, US-L5-09). **Verrouillée**
 * une fois `realisee` (garde `InalterabiliteCrmListener`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'crm_demande_rgpd')]
#[ApiResource(
    shortName: 'DemandeRGPD',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'crm.rgpd_gerer')"),
        new Get(security: "is_granted('PERM', 'crm.rgpd_gerer')"),
        new Post(
            security: "is_granted('PERM', 'crm.rgpd_demander') or is_granted('PERM', 'crm.rgpd_gerer')",
        ),
        new Post(
            uriTemplate: '/demandes-rgpd/{id}/traiter',
            read: true,
            input: false,
            security: "is_granted('PERM', 'crm.rgpd_gerer')",
            processor: TraiterDemandeRgpdProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['rgpd:read']],
    denormalizationContext: ['groups' => ['rgpd:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['client' => 'exact', 'statut' => 'exact'])]
class DemandeRGPD
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['rgpd:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['rgpd:read', 'rgpd:write'])]
    private ?Client $client = null;

    #[ORM\Column(length: 16, enumType: TypeDemandeRgpd::class)]
    #[Groups(['rgpd:read', 'rgpd:write'])]
    private TypeDemandeRgpd $type;

    #[ORM\Column(length: 12, enumType: StatutDemandeRgpd::class, options: ['default' => 'recue'])]
    #[Groups(['rgpd:read'])]
    private StatutDemandeRgpd $statut = StatutDemandeRgpd::Recue;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['rgpd:read'])]
    private \DateTimeImmutable $dateDemande;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['rgpd:read'])]
    private ?\DateTimeImmutable $dateTraitement = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['rgpd:read'])]
    private ?Utilisateur $traitePar = null;

    public function __construct(TypeDemandeRgpd $type = TypeDemandeRgpd::Anonymisation)
    {
        $this->id = Uuid::v4();
        $this->type = $type;
        $this->dateDemande = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function setClient(?Client $client): self
    {
        $this->client = $client;

        return $this;
    }

    public function getType(): TypeDemandeRgpd
    {
        return $this->type;
    }

    public function setType(TypeDemandeRgpd $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getStatut(): StatutDemandeRgpd
    {
        return $this->statut;
    }

    public function setStatut(StatutDemandeRgpd $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getDateDemande(): \DateTimeImmutable
    {
        return $this->dateDemande;
    }

    public function getDateTraitement(): ?\DateTimeImmutable
    {
        return $this->dateTraitement;
    }

    public function setDateTraitement(?\DateTimeImmutable $dateTraitement): self
    {
        $this->dateTraitement = $dateTraitement;

        return $this;
    }

    public function getTraitePar(): ?Utilisateur
    {
        return $this->traitePar;
    }

    public function setTraitePar(?Utilisateur $traitePar): self
    {
        $this->traitePar = $traitePar;

        return $this;
    }
}
