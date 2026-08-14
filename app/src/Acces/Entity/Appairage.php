<?php

declare(strict_types=1);

namespace App\Acces\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Acces\Enum\ModeAppairage;
use App\Acces\State\AppairageProcessor;
use App\Acces\State\RevoquerAppairageProcessor;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Lien Support ↔ DroitAcces (US-L3-02, A-02). Un seul appairage actif par support (CA-2) : garanti
 * par la colonne dénormalisée `supportActif` (= id du support tant que l'appairage est actif, sinon
 * NULL) sous contrainte unique — même technique que `caisse_session.pdv_actif` (M2 §7).
 */
#[ORM\Entity]
#[ORM\Table(name: 'acces_appairage')]
#[ORM\UniqueConstraint(name: 'uniq_appairage_support_actif', columns: ['support_actif'])]
#[ApiResource(
    shortName: 'Appairage',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'acces.lire')"),
        new Get(security: "is_granted('PERM', 'acces.lire')"),
        new Post(
            uriTemplate: '/acces/appairages',
            read: false,
            input: false,
            security: "is_granted('PERM', 'acces.appairer')",
            processor: AppairageProcessor::class,
        ),
        new Post(
            uriTemplate: '/acces/appairages/{id}/revoquer',
            read: true,
            input: false,
            security: "is_granted('PERM', 'acces.appairer')",
            processor: RevoquerAppairageProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['appairage:read']],
)]
class Appairage
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['appairage:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Support::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['appairage:read'])]
    private ?Support $support = null;

    #[ORM\ManyToOne(targetEntity: DroitAcces::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['appairage:read'])]
    private ?DroitAcces $droit = null;

    #[ORM\Column(length: 12, enumType: ModeAppairage::class)]
    #[Groups(['appairage:read'])]
    private ModeAppairage $mode = ModeAppairage::Caisse;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['appairage:read'])]
    private bool $actif = true;

    /** Dénormalisation de `support` quand `actif` = true, NULL sinon (unicité, CA-2). */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $supportActif = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['appairage:read'])]
    private \DateTimeImmutable $dateAppairage;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['appairage:read'])]
    private ?Utilisateur $agent = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['appairage:read'])]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->dateAppairage = new \DateTimeImmutable();
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
        $this->synchroniserSupportActif();

        return $this;
    }

    public function getDroit(): ?DroitAcces
    {
        return $this->droit;
    }

    public function setDroit(?DroitAcces $droit): self
    {
        $this->droit = $droit;

        return $this;
    }

    public function getMode(): ModeAppairage
    {
        return $this->mode;
    }

    public function setMode(ModeAppairage $mode): self
    {
        $this->mode = $mode;

        return $this;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): self
    {
        $this->actif = $actif;
        $this->synchroniserSupportActif();

        return $this;
    }

    public function getSupportActif(): ?Uuid
    {
        return $this->supportActif;
    }

    private function synchroniserSupportActif(): void
    {
        $this->supportActif = $this->actif ? $this->support?->getId() : null;
    }

    public function getDateAppairage(): \DateTimeImmutable
    {
        return $this->dateAppairage;
    }

    public function setDateAppairage(\DateTimeImmutable $dateAppairage): self
    {
        $this->dateAppairage = $dateAppairage;

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
