<?php

declare(strict_types=1);

namespace App\Sepa\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Organisation\Entity\Etablissement;
use App\Sepa\Enum\VarianteCreancierSepa;
use App\Sepa\State\ConfigCreancierSepaProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Configuration créancier SEPA d'un établissement (1 par établissement, plan §2). Pilote le bloc
 * créancier bi-régime du pain.008 généré par `Pain008Generator` : `variante` détermine si `UltmtCdtr`
 * (régie) est injecté ou non (privé). Dérivée par défaut du `ProfilExploitant.type` de l'établissement
 * (`ConfigCreancierSepaProcessor`), surchargeable explicitement à la création/modification.
 *
 * ⚠ IBAN — garde de sécurité applicative (spec §4, plan §2) : `creancierIbanToken` et
 * `creancierIbanChiffre` ne sont **jamais** portés par un groupe de sérialisation, donc jamais exposés
 * en API. Seul `creancierIban4Derniers` est lisible. L'IBAN en clair ne transite qu'en entrée du
 * processor (`creancierIbanClair`, transitoire, jamais mappé Doctrine) : tokenisé (non réversible,
 * affichage/recherche) **et** chiffré (réversible, `ChiffreurIbanInterface`, libsodium) avant
 * persistance. Le déchiffrement n'a lieu que côté serveur, au moment strict où `Pain008Generator`
 * construit la remise pain.008.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sepa_config_creancier')]
#[ORM\UniqueConstraint(name: 'uniq_config_creancier_etablissement', columns: ['etablissement_id'])]
#[ApiResource(
    shortName: 'ConfigCreancierSepa',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'sepa.lire') or is_granted('PERM', 'compta.lire')"),
        new Get(security: "is_granted('PERM', 'sepa.lire') or is_granted('PERM', 'compta.lire')"),
        new Post(security: "is_granted('PERM', 'sepa.gerer') or is_granted('PERM', 'compta.gerer')", processor: ConfigCreancierSepaProcessor::class),
        new Patch(security: "is_granted('PERM', 'sepa.gerer') or is_granted('PERM', 'compta.gerer')", processor: ConfigCreancierSepaProcessor::class),
    ],
    normalizationContext: ['groups' => ['config_creancier:read']],
    denormalizationContext: ['groups' => ['config_creancier:write']],
)]
class ConfigCreancierSepa
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['config_creancier:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    #[Assert\NotNull]
    #[Groups(['config_creancier:read', 'config_creancier:write'])]
    private ?Etablissement $etablissement = null;

    /** Dérivée par défaut du `ProfilExploitant.type` de l'établissement si non fournie (§1/§2 du plan). */
    #[ORM\Column(length: 8, enumType: VarianteCreancierSepa::class, nullable: true)]
    #[Groups(['config_creancier:read', 'config_creancier:write'])]
    private ?VarianteCreancierSepa $variante = null;

    /** Identifiant Créancier SEPA (ICS), ex. « FR00ZZZ000000 » (`CdtrSchmeId/Id/PrvtId/Othr/Id`). */
    #[ORM\Column(length: 35)]
    #[Assert\NotBlank]
    #[Groups(['config_creancier:read', 'config_creancier:write'])]
    private string $ics = '';

    /** Nom créancier de base — `InitgPty/Nm` toujours ; `Cdtr/Nm` en variante privée (§1 du plan). */
    #[ORM\Column(length: 140)]
    #[Assert\NotBlank]
    #[Groups(['config_creancier:read', 'config_creancier:write'])]
    private string $creancierNom = '';

    #[ORM\Column(length: 128, options: ['default' => ''])]
    private string $creancierIbanToken = '';

    #[ORM\Column(length: 4, options: ['default' => ''])]
    #[Groups(['config_creancier:read'])]
    private string $creancierIban4Derniers = '';

    /**
     * Coffre IBAN réversible (`ChiffreurIbanInterface`, libsodium) — nonce+cipher base64. Volontairement
     * **sans** `#[Groups]`, comme `creancierIbanToken` : ne doit jamais apparaître dans une réponse API.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $creancierIbanChiffre = null;

    #[ORM\Column(length: 11)]
    #[Assert\NotBlank]
    #[Groups(['config_creancier:read', 'config_creancier:write'])]
    private string $creancierBic = '';

    /** IBAN en clair — champ transitoire (jamais mappé Doctrine), consommé par le processor. */
    #[Groups(['config_creancier:write'])]
    private string $creancierIbanClair = '';

    /** Régie uniquement : nom de la collectivité, porté par `Cdtr/Nm` (§1 du plan). */
    #[ORM\Column(length: 140, nullable: true)]
    #[Groups(['config_creancier:read', 'config_creancier:write'])]
    private ?string $collectiviteNom = null;

    /** Régie uniquement : nom de la régie, porté par `InitgPty/Nm` et `UltmtCdtr/Nm` (§1 du plan). */
    #[ORM\Column(length: 140, nullable: true)]
    #[Groups(['config_creancier:read', 'config_creancier:write'])]
    private ?string $ultimateCreancierNom = null;

    /** Régie uniquement : identifiant organisation de la régie, porté par `UltmtCdtr/Id/OrgId/Othr/Id`. */
    #[ORM\Column(length: 35, nullable: true)]
    #[Groups(['config_creancier:read', 'config_creancier:write'])]
    private ?string $ultimateCreancierOrgId = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['config_creancier:read'])]
    private \DateTimeImmutable $modifieLe;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->modifieLe = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getVariante(): ?VarianteCreancierSepa
    {
        return $this->variante;
    }

    public function setVariante(?VarianteCreancierSepa $variante): self
    {
        $this->variante = $variante;

        return $this;
    }

    public function getIcs(): string
    {
        return $this->ics;
    }

    public function setIcs(string $ics): self
    {
        $this->ics = $ics;

        return $this;
    }

    public function getCreancierNom(): string
    {
        return $this->creancierNom;
    }

    public function setCreancierNom(string $creancierNom): self
    {
        $this->creancierNom = $creancierNom;

        return $this;
    }

    public function getCreancierIbanToken(): string
    {
        return $this->creancierIbanToken;
    }

    public function setCreancierIbanToken(string $creancierIbanToken): self
    {
        $this->creancierIbanToken = $creancierIbanToken;

        return $this;
    }

    public function getCreancierIban4Derniers(): string
    {
        return $this->creancierIban4Derniers;
    }

    public function setCreancierIban4Derniers(string $creancierIban4Derniers): self
    {
        $this->creancierIban4Derniers = $creancierIban4Derniers;

        return $this;
    }

    public function getCreancierIbanChiffre(): ?string
    {
        return $this->creancierIbanChiffre;
    }

    public function setCreancierIbanChiffre(?string $creancierIbanChiffre): self
    {
        $this->creancierIbanChiffre = $creancierIbanChiffre;

        return $this;
    }

    public function getCreancierBic(): string
    {
        return $this->creancierBic;
    }

    public function setCreancierBic(string $creancierBic): self
    {
        $this->creancierBic = $creancierBic;

        return $this;
    }

    public function getCreancierIbanClair(): string
    {
        return $this->creancierIbanClair;
    }

    public function setCreancierIbanClair(string $creancierIbanClair): self
    {
        $this->creancierIbanClair = $creancierIbanClair;

        return $this;
    }

    public function getCollectiviteNom(): ?string
    {
        return $this->collectiviteNom;
    }

    public function setCollectiviteNom(?string $collectiviteNom): self
    {
        $this->collectiviteNom = $collectiviteNom;

        return $this;
    }

    public function getUltimateCreancierNom(): ?string
    {
        return $this->ultimateCreancierNom;
    }

    public function setUltimateCreancierNom(?string $ultimateCreancierNom): self
    {
        $this->ultimateCreancierNom = $ultimateCreancierNom;

        return $this;
    }

    public function getUltimateCreancierOrgId(): ?string
    {
        return $this->ultimateCreancierOrgId;
    }

    public function setUltimateCreancierOrgId(?string $ultimateCreancierOrgId): self
    {
        $this->ultimateCreancierOrgId = $ultimateCreancierOrgId;

        return $this;
    }

    public function getModifieLe(): \DateTimeImmutable
    {
        return $this->modifieLe;
    }

    public function toucherModifieLe(): self
    {
        $this->modifieLe = new \DateTimeImmutable();

        return $this;
    }
}
