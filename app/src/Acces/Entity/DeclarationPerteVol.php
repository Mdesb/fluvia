<?php

declare(strict_types=1);

namespace App\Acces\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Acces\State\AnnulerDeclarationProcessor;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Déclaration de perte/vol (US-L3-09, RG-ACC-07) : blocage serveur immédiat, tracée (motif, agent,
 * horodatage), réversible par un rôle habilité (`POST /acces/declarations/{id}/annuler`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'acces_declaration_perte_vol')]
#[ApiResource(
    shortName: 'DeclarationPerteVol',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'acces.lire')"),
        new Get(security: "is_granted('PERM', 'acces.lire')"),
        new Post(
            uriTemplate: '/acces/declarations/{id}/annuler',
            read: true,
            input: false,
            security: "is_granted('PERM', 'acces.bloquer_support')",
            processor: AnnulerDeclarationProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['pertevol:read']],
)]
class DeclarationPerteVol
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['pertevol:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Support::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['pertevol:read'])]
    private ?Support $support = null;

    #[ORM\Column(length: 255)]
    #[Groups(['pertevol:read'])]
    private string $motif = '';

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['pertevol:read'])]
    private ?Utilisateur $agent = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['pertevol:read'])]
    private \DateTimeImmutable $horodatage;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['pertevol:read'])]
    private bool $annulee = false;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['pertevol:read'])]
    private ?Utilisateur $annuleePar = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['pertevol:read'])]
    private ?\DateTimeImmutable $annuleeLe = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['pertevol:read'])]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->horodatage = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSupport(): ?Support
    {
        return $this->support;
    }

    public function setSupport(?Support $support): self
    {
        $this->support = $support;

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

    public function getAgent(): ?Utilisateur
    {
        return $this->agent;
    }

    public function setAgent(?Utilisateur $agent): self
    {
        $this->agent = $agent;

        return $this;
    }

    public function getHorodatage(): \DateTimeImmutable
    {
        return $this->horodatage;
    }

    public function setHorodatage(\DateTimeImmutable $horodatage): self
    {
        $this->horodatage = $horodatage;

        return $this;
    }

    public function isAnnulee(): bool
    {
        return $this->annulee;
    }

    public function setAnnulee(bool $annulee): self
    {
        $this->annulee = $annulee;

        return $this;
    }

    public function getAnnuleePar(): ?Utilisateur
    {
        return $this->annuleePar;
    }

    public function setAnnuleePar(?Utilisateur $annuleePar): self
    {
        $this->annuleePar = $annuleePar;

        return $this;
    }

    public function getAnnuleeLe(): ?\DateTimeImmutable
    {
        return $this->annuleeLe;
    }

    public function setAnnuleeLe(?\DateTimeImmutable $annuleeLe): self
    {
        $this->annuleeLe = $annuleeLe;

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
