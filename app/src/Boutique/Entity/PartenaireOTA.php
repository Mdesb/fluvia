<?php

declare(strict_types=1);

namespace App\Boutique\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Boutique\State\EstablishmentStampProcessor;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Partenaire OTA générique (US-L8-14, RG-M3-09, §0 décision n°7 du plan) : propriété fonctionnelle
 * de L8, scopée `Vitrine`. **Distinct** de `App\Musee\Entity\PartenaireOTA` (non touché) — la
 * réconciliation reste un futur remaniement de specs (Risque n°8 du plan).
 */
#[ORM\Entity]
#[ORM\Table(name: 'bou_partenaire_ota')]
#[ApiResource(
    shortName: 'BoutiquePartenaireOta',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'boutique.lire')"),
        new Get(security: "is_granted('PERM', 'boutique.lire')"),
        new Post(
            security: "is_granted('PERM', 'boutique.gerer_connecteur_ota')",
            processor: EstablishmentStampProcessor::class,
        ),
        new Patch(security: "is_granted('PERM', 'boutique.gerer_connecteur_ota')"),
    ],
    normalizationContext: ['groups' => ['partenaire_ota:read']],
    denormalizationContext: ['groups' => ['partenaire_ota:write']],
)]
class PartenaireOTA
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['partenaire_ota:read', 'allocation_ota:read', 'reversement_ota:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Vitrine::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['partenaire_ota:read', 'partenaire_ota:write'])]
    private ?Vitrine $vitrine = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Groups(['partenaire_ota:read', 'partenaire_ota:write'])]
    private string $nom = '';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Assert\PositiveOrZero]
    #[Groups(['partenaire_ota:read', 'partenaire_ota:write'])]
    private string $tarifNet = '0.00';

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2)]
    #[Assert\PositiveOrZero]
    #[Groups(['partenaire_ota:read', 'partenaire_ota:write'])]
    private string $commission = '0.00';

    #[ORM\Column(length: 60, nullable: true)]
    #[Groups(['partenaire_ota:read', 'partenaire_ota:write'])]
    private ?string $codeConnecteur = null;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['partenaire_ota:read', 'partenaire_ota:write'])]
    private bool $actif = true;

    // D41 — l'etablissement n'est expose dans AUCUN groupe, ce qui est juste ; mais rien ne le
    // posait non plus, alors que la colonne est NOT NULL et qu'aucun processor n'etait branche sur
    // le `Post`. Toute creation finissait donc en violation d'integrite. `EstablishmentStampProcessor`
    // le pose depuis la session serveur.
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

    public function getVitrine(): ?Vitrine
    {
        return $this->vitrine;
    }

    public function setVitrine(?Vitrine $vitrine): self
    {
        $this->vitrine = $vitrine;
        $this->etablissement = $vitrine?->getEtablissement();

        return $this;
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

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): self
    {
        $this->actif = $actif;

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

    /** Montant reversé pour une vente (tarif net + commission, RG-M3-09). */
    public function montantParVente(): string
    {
        $net = (float) $this->tarifNet;
        $commission = $net * ((float) $this->commission) / 100;

        return number_format($net + $commission, 2, '.', '');
    }
}
