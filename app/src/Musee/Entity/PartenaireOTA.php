<?php

declare(strict_types=1);

namespace App\Musee\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Partenaire OTA (US-MUSEE-07, RG-MUS-04) : distributeur externe (Tiqets, Weezevent…) — l'intégration
 * technique par plateforme est **hors périmètre** (port `ConnecteurOtaInterface`, §1.8 du plan).
 */
#[ORM\Entity]
#[ORM\Table(name: 'musee_partenaire_ota')]
#[ApiResource(
    shortName: 'MuseePartenaireOTA',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'musee.lire')"),
        new Get(security: "is_granted('PERM', 'musee.lire')"),
        new Post(security: "is_granted('PERM', 'musee.gerer_ota')"),
        new Patch(security: "is_granted('PERM', 'musee.gerer_ota')"),
    ],
    normalizationContext: ['groups' => ['partenaire:read']],
    denormalizationContext: ['groups' => ['partenaire:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['etablissement' => 'exact', 'nom' => 'partial'])]
class PartenaireOTA
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['partenaire:read', 'allocation:read', 'resa_ota:read', 'reversement:read'])]
    private Uuid $id;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Groups(['partenaire:read', 'partenaire:write'])]
    private string $nom = '';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Assert\PositiveOrZero]
    #[Groups(['partenaire:read', 'partenaire:write'])]
    private string $tarifNet = '0.00';

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2)]
    #[Assert\PositiveOrZero]
    #[Groups(['partenaire:read', 'partenaire:write'])]
    private string $commission = '0.00';

    #[ORM\Column(length: 60, nullable: true)]
    #[Groups(['partenaire:read', 'partenaire:write'])]
    private ?string $codeConnecteur = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['partenaire:read'])]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): self
    {
        $this->nom = $nom;

        return $this;
    }

    public function getTarifNet(): string
    {
        return $this->tarifNet;
    }

    public function setTarifNet(string $tarifNet): self
    {
        $this->tarifNet = $tarifNet;

        return $this;
    }

    public function getCommission(): string
    {
        return $this->commission;
    }

    public function setCommission(string $commission): self
    {
        $this->commission = $commission;

        return $this;
    }

    public function getCodeConnecteur(): ?string
    {
        return $this->codeConnecteur;
    }

    public function setCodeConnecteur(?string $codeConnecteur): self
    {
        $this->codeConnecteur = $codeConnecteur;

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

    /** Montant reversé pour une vente (tarif net + commission, RG-MUS-04). */
    public function montantParVente(): string
    {
        $net = (float) $this->tarifNet;
        $commission = $net * ((float) $this->commission) / 100;

        return number_format($net + $commission, 2, '.', '');
    }
}
