<?php

declare(strict_types=1);

namespace App\Acces\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Acces\Enum\ModeRecalage;
use App\Acces\Enum\ModeSeuil;
use App\Organisation\Entity\Espace;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Nœud racine de la topologie Espace › Contrôleur › Équipement (US-L3-01, écran A-01). Porte le seuil
 * de jauge/FMI, le mode au dépassement (blocage/alerte) et les réglages d'anti-passback par défaut,
 * surchargeables au niveau équipement (§4.1). Rattaché à un Espace/Établissement du socle
 * (RG-SOCLE-01).
 */
#[ORM\Entity]
#[ORM\Table(name: 'acces_espace_acces')]
#[ApiResource(
    shortName: 'EspaceAcces',
    operations: [
        new GetCollection(security: "is_granted('PERM', 'acces.lire')"),
        new Get(security: "is_granted('PERM', 'acces.lire')"),
        new Post(security: "is_granted('PERM', 'acces.gerer')"),
        new Patch(security: "is_granted('PERM', 'acces.gerer')"),
    ],
    normalizationContext: ['groups' => ['espace_acces:read']],
    denormalizationContext: ['groups' => ['espace_acces:write']],
)]
class EspaceAcces
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['espace_acces:read', 'controleur:read', 'jauge:read', 'passage:read'])]
    private Uuid $id;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Groups(['espace_acces:read', 'espace_acces:write', 'controleur:read', 'passage:read', 'sos:read'])]
    private string $libelle = '';

    #[ORM\ManyToOne(targetEntity: Espace::class)]
    #[ORM\JoinColumn(name: 'espace_socle_id', nullable: false)]
    #[Assert\NotNull(message: 'Un espace du socle est requis (RG-SOCLE-01).')]
    #[Groups(['espace_acces:read', 'espace_acces:write'])]
    private ?Espace $espaceSocle = null;

    #[ORM\Column]
    #[Assert\PositiveOrZero(message: 'Le seuil FMI doit être un entier positif ou nul (RG-ACC-04).')]
    #[Groups(['espace_acces:read', 'espace_acces:write'])]
    private int $seuilFmi = 0;

    #[ORM\Column(length: 12, enumType: ModeSeuil::class, options: ['default' => 'blocage'])]
    #[Groups(['espace_acces:read', 'espace_acces:write'])]
    private ModeSeuil $modeSeuil = ModeSeuil::Blocage;

    #[ORM\Column(type: 'smallint', nullable: true)]
    #[Assert\Range(min: 0, max: 100)]
    #[Groups(['espace_acces:read', 'espace_acces:write'])]
    private ?int $preAlertePct = null;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['espace_acces:read', 'espace_acces:write'])]
    private bool $antiPassbackActif = true;

    #[ORM\Column(options: ['default' => 300])]
    #[Assert\Positive]
    #[Groups(['espace_acces:read', 'espace_acces:write'])]
    private int $antiPassbackDelai = 300;

    #[ORM\Column(length: 16, enumType: ModeRecalage::class, options: ['default' => 'remise_a_zero'])]
    #[Groups(['espace_acces:read', 'espace_acces:write'])]
    private ModeRecalage $recalageOuverture = ModeRecalage::RemiseAZero;

    #[ORM\ManyToOne(targetEntity: SousReseau::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['espace_acces:read', 'espace_acces:write'])]
    private ?SousReseau $sousReseau = null;

    #[ORM\ManyToOne(targetEntity: Etablissement::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['espace_acces:read'])]
    private ?Etablissement $etablissement = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): self
    {
        $this->libelle = $libelle;

        return $this;
    }

    public function getEspaceSocle(): ?Espace
    {
        return $this->espaceSocle;
    }

    public function setEspaceSocle(?Espace $espaceSocle): self
    {
        $this->espaceSocle = $espaceSocle;
        if ($espaceSocle !== null) {
            $this->etablissement = $espaceSocle->getEtablissement();
        }

        return $this;
    }

    public function getSeuilFmi(): int
    {
        return $this->seuilFmi;
    }

    public function setSeuilFmi(int $seuilFmi): self
    {
        $this->seuilFmi = $seuilFmi;

        return $this;
    }

    public function getModeSeuil(): ModeSeuil
    {
        return $this->modeSeuil;
    }

    public function setModeSeuil(ModeSeuil $modeSeuil): self
    {
        $this->modeSeuil = $modeSeuil;

        return $this;
    }

    public function getPreAlertePct(): ?int
    {
        return $this->preAlertePct;
    }

    public function setPreAlertePct(?int $preAlertePct): self
    {
        $this->preAlertePct = $preAlertePct;

        return $this;
    }

    public function isAntiPassbackActif(): bool
    {
        return $this->antiPassbackActif;
    }

    public function setAntiPassbackActif(bool $antiPassbackActif): self
    {
        $this->antiPassbackActif = $antiPassbackActif;

        return $this;
    }

    public function getAntiPassbackDelai(): int
    {
        return $this->antiPassbackDelai;
    }

    public function setAntiPassbackDelai(int $antiPassbackDelai): self
    {
        $this->antiPassbackDelai = $antiPassbackDelai;

        return $this;
    }

    public function getRecalageOuverture(): ModeRecalage
    {
        return $this->recalageOuverture;
    }

    public function setRecalageOuverture(ModeRecalage $recalageOuverture): self
    {
        $this->recalageOuverture = $recalageOuverture;

        return $this;
    }

    public function getSousReseau(): ?SousReseau
    {
        return $this->sousReseau;
    }

    public function setSousReseau(?SousReseau $sousReseau): self
    {
        $this->sousReseau = $sousReseau;

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
