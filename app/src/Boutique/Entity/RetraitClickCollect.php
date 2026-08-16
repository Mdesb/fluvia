<?php

declare(strict_types=1);

namespace App\Boutique\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Boutique\Enum\StatutRetraitClickCollect;
use App\Boutique\State\ValiderRetraitClickCollectProcessor;
use App\Organisation\Entity\Espace;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Vente\Entity\BilletSupport;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Retrait / click & collect d'un support physique (US-L8-13, RG-M3-18). Un billet QR provisoire est
 * émis immédiatement à la confirmation ; au retrait (code présenté), le support physique est appairé
 * au droit d'accès et remplace le QR provisoire pour l'usage courant.
 */
#[ORM\Entity]
#[ORM\Table(name: 'bou_retrait_click_collect')]
#[ORM\UniqueConstraint(name: 'uniq_retrait_billet_support', columns: ['billet_support_id'])]
#[ORM\UniqueConstraint(name: 'uniq_retrait_code', columns: ['code_retrait'])]
#[ApiResource(
    shortName: 'RetraitClickCollect',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'boutique.lire') or is_granted('PERM', 'boutique.lire_soi')"),
        new Get(security: "is_granted('PERM', 'boutique.lire') or is_granted('PERM', 'boutique.lire_soi')"),
        new Post(
            uriTemplate: '/boutique/retraits/{id}/valider',
            read: true,
            input: false,
            security: "is_granted('PERM', 'boutique.traiter_retrait') or is_granted('PERM', 'acces.controler')",
            processor: ValiderRetraitClickCollectProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['retrait:read']],
)]
class RetraitClickCollect
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['retrait:read'])]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: BilletSupport::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['retrait:read'])]
    private ?BilletSupport $billetSupport = null;

    #[ORM\ManyToOne(targetEntity: Espace::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['retrait:read'])]
    private ?Espace $pointRetrait = null;

    #[ORM\Column(length: 12)]
    #[Groups(['retrait:read'])]
    private string $codeRetrait = '';

    #[ORM\Column(length: 10, enumType: StatutRetraitClickCollect::class, options: ['default' => 'a_retirer'])]
    #[Groups(['retrait:read'])]
    private StatutRetraitClickCollect $statut = StatutRetraitClickCollect::ARetirer;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['retrait:read'])]
    private ?\DateTimeImmutable $dateRetrait = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['retrait:read'])]
    private ?Utilisateur $traitePar = null;

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

    public function getBilletSupport(): ?BilletSupport
    {
        return $this->billetSupport;
    }

    public function setBilletSupport(?BilletSupport $billetSupport): self
    {
        $this->billetSupport = $billetSupport;

        return $this;
    }

    public function getPointRetrait(): ?Espace
    {
        return $this->pointRetrait;
    }

    public function setPointRetrait(?Espace $pointRetrait): self
    {
        $this->pointRetrait = $pointRetrait;

        return $this;
    }

    public function getCodeRetrait(): string
    {
        return $this->codeRetrait;
    }

    public function setCodeRetrait(string $codeRetrait): self
    {
        $this->codeRetrait = $codeRetrait;

        return $this;
    }

    public function getStatut(): StatutRetraitClickCollect
    {
        return $this->statut;
    }

    public function setStatut(StatutRetraitClickCollect $statut): self
    {
        $this->statut = $statut;

        return $this;
    }

    public function getDateRetrait(): ?\DateTimeImmutable
    {
        return $this->dateRetrait;
    }

    public function setDateRetrait(?\DateTimeImmutable $dateRetrait): self
    {
        $this->dateRetrait = $dateRetrait;

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
